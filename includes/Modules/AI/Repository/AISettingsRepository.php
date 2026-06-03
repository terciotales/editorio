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

        $settings = array_merge($this->get_defaults(), $stored);
        if (! isset($settings['rewrite_prompt']) && isset($settings['system_instruction'])) {
            $settings['rewrite_prompt'] = (string) $settings['system_instruction'];
        }
        $settings['system_instruction'] = (string) ($settings['rewrite_prompt'] ?? '');

        return $settings;
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
            'rewrite_prompt' => 'Rewrite the following news article in a completely original way. Do not copy phrases. Keep factual accuracy. Use neutral journalistic tone. Output in HTML.',
            'curation_prompt' => 'Use this only as an editorial note. Always synthesize related news items into new headlines, explain why they belong together, and cite the source ids. Return only valid JSON with stories containing title, reason, and source_ids.',
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

        $rewrite_prompt = (string) ($settings['rewrite_prompt'] ?? $settings['system_instruction'] ?? $defaults['rewrite_prompt']);
        $curation_prompt = (string) ($settings['curation_prompt'] ?? $defaults['curation_prompt']);

        return [
            'enabled' => ! empty($settings['enabled']),
            'rewrite_prompt' => sanitize_textarea_field($rewrite_prompt),
            'curation_prompt' => sanitize_textarea_field($curation_prompt),
        ];
    }
}
