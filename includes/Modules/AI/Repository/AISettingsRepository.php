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
        return update_option(self::OPTION_KEY, $this->sanitize_settings($merged));
    }
    /**
     * @return array<string,mixed>
     */
    public function get_defaults(): array
    {
        return [
            'enabled' => false,
            'system_instruction' => 'Rewrite the following news article in a completely original way. Do not copy phrases. Keep factual accuracy. Use neutral journalistic tone. Output in HTML.',
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
            'system_instruction' => sanitize_textarea_field((string) ($settings['system_instruction'] ?? $defaults['system_instruction'])),
        ];
    }
}
