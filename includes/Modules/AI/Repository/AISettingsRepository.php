<?php
declare(strict_types=1);
namespace Editorio\Modules\AI\Repository;
final class AISettingsRepository
{
    private const OPTION_KEY = 'editorio_ai_settings';
    /**
     * @return array<string,mixed>
     */
    public function get_settings(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (! is_array($stored)) {
            $stored = [];
        }
        return array_merge($this->get_defaults(), $stored);
    }
    /**
     * @param array<string,mixed> $settings
     */
    public function save_settings(array $settings): bool
    {
        $current = $this->get_settings();
        $merged = array_merge($current, $settings);
        if (array_key_exists('api_key', $settings) && (string) $settings['api_key'] === '') {
            $merged['api_key'] = (string) ($current['api_key'] ?? '');
        }
        return update_option(self::OPTION_KEY, $this->sanitize_settings($merged));
    }
    /**
     * @return array<string,mixed>
     */
    public function get_defaults(): array
    {
        return [
            'enabled' => false,
            'provider' => 'openai',
            'api_key' => '',
            'model' => 'gpt-4o-mini',
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'temperature' => 0.2,
            'max_tokens' => 1200,
        ];
    }
    /**
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    private function sanitize_settings(array $settings): array
    {
        $defaults = $this->get_defaults();
        return [
            'enabled' => ! empty($settings['enabled']),
            'provider' => sanitize_key((string) ($settings['provider'] ?? $defaults['provider'])),
            'api_key' => sanitize_text_field((string) ($settings['api_key'] ?? '')),
            'model' => sanitize_text_field((string) ($settings['model'] ?? $defaults['model'])),
            'endpoint' => esc_url_raw((string) ($settings['endpoint'] ?? $defaults['endpoint'])),
            'temperature' => (float) ($settings['temperature'] ?? $defaults['temperature']),
            'max_tokens' => max(1, (int) ($settings['max_tokens'] ?? $defaults['max_tokens'])),
        ];
    }
}
