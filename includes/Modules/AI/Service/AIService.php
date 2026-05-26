<?php
declare(strict_types=1);
namespace Editorio\Modules\AI\Service;
use Editorio\Modules\AI\Repository\AISettingsRepository;

final class AIService
{
    private AISettingsRepository $settings_repository;
    public function __construct(AISettingsRepository $settings_repository)
    {
        $this->settings_repository = $settings_repository;
    }
    /**
     * @return array<string,mixed>
     */
    public function get_settings(): array
    {
        return array_merge(
            $this->settings_repository->get_settings(),
            [
                'dependency' => $this->get_dependency_status(),
            ]
        );
    }
    /**
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    public function save_settings(array $settings): array
    {
        $this->settings_repository->save_settings($settings);
        return $this->get_settings();
    }
    /**
     * @return array{available:bool,has_credentials:bool,has_valid_credentials:bool,connectors_url:string}
     */
    public function get_dependency_status(): array
    {
        $available = function_exists('WordPress\\AI\\get_ai_service')
            && function_exists('WordPress\\AI\\has_ai_credentials')
            && function_exists('WordPress\\AI\\has_valid_ai_credentials')
            && function_exists('WordPress\\AI\\get_provider_availability_data');
        if (! $available) {
            return [
                'available' => false,
                'has_credentials' => false,
                'has_valid_credentials' => false,
                'connectors_url' => admin_url('options-connectors.php'),
            ];
        }
        $provider_data = \WordPress\AI\get_provider_availability_data();
        return [
            'available' => true,
            'has_credentials' => (bool) \WordPress\AI\has_ai_credentials(),
            'has_valid_credentials' => (bool) \WordPress\AI\has_valid_ai_credentials(),
            'connectors_url' => (string) ($provider_data['connectorsUrl'] ?? admin_url('options-connectors.php')),
        ];
    }
    /**
     * @return array<string,mixed>
     */
    public function rewrite(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [
                'success' => false,
                'error' => __('Content is required.', 'editorio'),
            ];
        }
        $settings = $this->settings_repository->get_settings();
        if (empty($settings['enabled'])) {
            return [
                'success' => false,
                'error' => __('AI is disabled in settings.', 'editorio'),
            ];
        }
        $dependency = $this->get_dependency_status();
        if (! $dependency['available']) {
            return [
                'success' => false,
                'error' => __('WordPress AI plugin is not active. Activate it to use Editorio AI.', 'editorio'),
            ];
        }
        if (! $dependency['has_credentials']) {
            return [
                'success' => false,
                'error' => __('No AI connector configured. Configure a provider in the WordPress AI connectors screen.', 'editorio'),
            ];
        }
        if (! $dependency['has_valid_credentials']) {
            return [
                'success' => false,
                'error' => __('AI connector credentials are invalid or unavailable for text generation.', 'editorio'),
            ];
        }
        try {
            $response = \WordPress\AI\get_ai_service()->create_textgen_prompt($content, [
                'system_instruction' => (string) $settings['system_instruction'],
            ])->generate_text();
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }
        $parsed = $this->parse_response($response);
        if ($parsed['is_json']) {
            return [
                'success' => true,
                'format' => 'json',
                'content' => (string) ($parsed['data']['content'] ?? $response),
                'data' => $parsed['data'],
                'raw' => $response,
            ];
        }
        return [
            'success' => true,
            'format' => 'html',
            'content' => $response,
            'raw' => $response,
        ];
    }
    /**
     * @return array{is_json:bool,data:array<string,mixed>}
     */
    private function parse_response(string $response): array
    {
        $decoded = json_decode(trim($response), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'is_json' => true,
                'data' => $decoded,
            ];
        }
        return [
            'is_json' => false,
            'data' => [],
        ];
    }
}
