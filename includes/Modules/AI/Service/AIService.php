<?php
declare(strict_types=1);
namespace Editorio\Modules\AI\Service;
use Editorio\Modules\AI\Provider\AIProviderFactory;
use Editorio\Modules\AI\Repository\AISettingsRepository;
use RuntimeException;
final class AIService
{
    private AISettingsRepository $settings_repository;
    private AIProviderFactory $provider_factory;
    public function __construct(AISettingsRepository $settings_repository, AIProviderFactory $provider_factory)
    {
        $this->settings_repository = $settings_repository;
        $this->provider_factory = $provider_factory;
    }
    /**
     * @return array<string,mixed>
     */
    public function get_settings(): array
    {
        return $this->settings_repository->get_settings();
    }
    /**
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    public function save_settings(array $settings): array
    {
        $this->settings_repository->save_settings($settings);
        return $this->settings_repository->get_settings();
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
        $provider = $this->provider_factory->create($settings);
        $messages = [
            [
                'role' => 'system',
                'content' => 'Rewrite the following news article in a completely original way. Do not copy phrases. Keep factual accuracy. Use neutral journalistic tone. Output in HTML.',
            ],
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];
        try {
            $response = $provider->generate($messages, [
                'model' => (string) $settings['model'],
                'temperature' => (float) $settings['temperature'],
                'max_tokens' => (int) $settings['max_tokens'],
                'timeout' => 45,
            ]);
        } catch (RuntimeException $exception) {
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
