<?php
declare(strict_types=1);
namespace Editorio\Modules\AI\Service;
use Editorio\Modules\AI\Repository\AISettingsRepository;
use function WordPress\AI\get_ai_service;
use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\has_valid_ai_credentials;

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
            'has_credentials' => (bool) has_ai_credentials(),
            'has_valid_credentials' => (bool) has_valid_ai_credentials(),
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
            $response = get_ai_service()->create_textgen_prompt($content, [
                'system_instruction' => (string) ($settings['rewrite_prompt'] ?? ''),
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
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public function curate_items(array $items, int $limit = 10): array
    {
        $items = array_values(array_filter($items, static fn ($item): bool => is_array($item)));
        if ($items === []) {
            return [];
        }

        $limit = max(1, $limit);
        $current_date = current_time('mysql');
        $items = $this->sort_curation_items_by_recent_first($items);

        $settings = $this->settings_repository->get_settings();
        if (empty($settings['enabled'])) {
            return $this->build_curation_fallback(
                $items,
                $limit,
                __('AI is disabled in settings.', 'editorio')
            );
        }

        $dependency = $this->get_dependency_status();
        if (! $dependency['available'] || ! $dependency['has_credentials'] || ! $dependency['has_valid_credentials']) {
            if (! $dependency['available']) {
                $error = __('WordPress AI plugin is not active. Activate it to use Editorio AI.', 'editorio');
            } elseif (! $dependency['has_credentials']) {
                $error = __('No AI connector configured. Configure a provider in the WordPress AI connectors screen.', 'editorio');
            } else {
                $error = __('AI connector credentials are invalid or unavailable for text generation.', 'editorio');
            }

            return $this->build_curation_fallback($items, $limit, $error);
        }

        $payload = array_map(static function (array $item): array {
            return [
                'id' => (int) ($item['id'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
                'summary' => (string) ($item['summary'] ?? ''),
                'source_name' => (string) ($item['source_name'] ?? ''),
                'published_at' => (string) ($item['published_at'] ?? ''),
            ];
        }, $items);

        $prompt = wp_json_encode([
            'task' => 'Always cluster related news items into new synthesized editorial stories. Never output one story per item if several items refer to the same event or topic.',
            'current_date' => $current_date,
            'limit' => $limit,
            'items' => $payload,
            'output_format' => [
                'stories' => [
                    [
                        'title' => 'Original synthesized headline',
                        'source_ids' => [1, 2, 3],
                        'source_names' => ['Source A', 'Source B'],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($prompt) || $prompt === '') {
            return $fallback;
        }

        $system_instruction = $this->build_curation_instruction((string) ($settings['curation_prompt'] ?? ''));

        try {
            $response = get_ai_service()->create_textgen_prompt($prompt, [
                'system_instruction' => $system_instruction,
            ])->generate_text();

            \Nucleoweb_Log::d($response);
        } catch (\Throwable $exception) {
            return $this->build_curation_fallback($items, $limit, $exception->getMessage());
        }

        if (is_wp_error($response) || ! is_string($response) || $response === '') {
            return $this->build_curation_fallback(
                $items,
                $limit,
                __('AI provider returned an empty response.', 'editorio')
            );
        }

        $item_map = [];
        foreach ($items as $item) {
            $item_map[(int) ($item['id'] ?? 0)] = $item;
        }

        $decoded = $this->decode_json_response($response);
        $stories = $this->normalize_curation_stories($decoded, $item_map, $limit);
        $stories = $this->merge_similar_curation_stories($stories);

        if ($stories === []) {
            return $this->build_curation_fallback(
                $items,
                $limit,
                __('AI provider returned an invalid response.', 'editorio')
            );
        }

        return $this->decorate_curation_stories($stories, 'ai', '');
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function build_curation_fallback(array $items, int $limit, string $error = ''): array
    {
        $item_map = [];
        foreach ($items as $item) {
            $item_map[(int) ($item['id'] ?? 0)] = $item;
        }

        $groups = [];

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $key = $this->build_curation_topic_key($title);
            if ($key === '') {
                continue;
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'title' => $title,
                    'reason' => '',
                    'source_ids' => [],
                    'sources' => [],
                ];
            }

            $groups[$key]['source_ids'][] = (int) ($item['id'] ?? 0);
            $groups[$key]['sources'][] = $this->build_curation_source_reference($item);
        }

        $fallback = [];
        foreach ($groups as $group) {
            $story = $this->hydrate_curation_story($group, $item_map, $limit, 'automatic', $error);
            if ($story === null) {
                continue;
            }

            $fallback[] = $story;
            if (count($fallback) >= $limit) {
                break;
            }
        }

        return $this->decorate_curation_stories(
            $this->merge_similar_curation_stories($fallback),
            'automatic',
            $error
        );
    }

    /**
     * @param array<string,mixed> $decoded
     * @param array<int,array<string,mixed>> $item_map
     * @return array<int,array<string,mixed>>
     */
    private function normalize_curation_stories(array $decoded, array $item_map, int $limit): array
    {
        $raw_stories = $decoded['stories'] ?? null;
        if (! is_array($raw_stories) || $raw_stories === []) {
            $legacy_ids = $decoded['selected_ids'] ?? [];
            if (! is_array($legacy_ids) || $legacy_ids === []) {
                return [];
            }

            $legacy_story = [
                'title' => (string) ($decoded['title'] ?? ''),
                'reason' => (string) ($decoded['reason'] ?? ''),
                'source_ids' => array_values(array_filter(array_map('intval', $legacy_ids))),
            ];

            $story = $this->hydrate_curation_story($legacy_story, $item_map, $limit, 'ai', '');
            $stories = $story !== null ? [$story] : [];
            return $this->merge_similar_curation_stories($stories);
        }

        $normalized = [];
        $seen_titles = [];

        foreach ($raw_stories as $story) {
            if (! is_array($story)) {
                continue;
            }

            $hydrated = $this->hydrate_curation_story($story, $item_map, $limit, 'ai', '');
            if ($hydrated === null) {
                continue;
            }

            $title_key = $this->normalize_curation_key((string) ($hydrated['title'] ?? ''));
            if ($title_key === '' || isset($seen_titles[$title_key])) {
                continue;
            }

            $seen_titles[$title_key] = true;
            $normalized[] = $hydrated;

            if (count($normalized) >= $limit) {
                break;
            }
        }

        return $this->merge_similar_curation_stories($normalized);
    }

    /**
     * @param array<string,mixed> $story
     * @param array<int,array<string,mixed>> $item_map
     * @return array<string,mixed>|null
     */
    private function hydrate_curation_story(
        array $story,
        array $item_map,
        int $limit,
        string $curation_mode = 'ai',
        string $curation_error = ''
    ): ?array
    {
        $title = trim((string) ($story['title'] ?? ''));
        $source_ids = $story['source_ids'] ?? [];

        if (! is_array($source_ids)) {
            $source_ids = [];
        }

        $source_ids = array_values(array_unique(array_filter(array_map('intval', $source_ids))));
        $sources = [];

        foreach ($source_ids as $source_id) {
            if (! isset($item_map[$source_id])) {
                continue;
            }

            $sources[] = $this->build_curation_source_reference($item_map[$source_id]);
        }

        if ($title === '' && $sources !== []) {
            $title = $this->build_curation_title_from_sources($sources);
        }

        if ($title === '' || $sources === []) {
            return null;
        }

        $representative_item_id = (int) ($sources[0]['collector_item_id'] ?? 0);
        if ($representative_item_id <= 0) {
            return null;
        }

        return [
            'title' => $title,
            'reason' => '',
            'source_ids' => array_slice($source_ids, 0, $limit),
            'sources' => $sources,
            'representative_item_id' => $representative_item_id,
            'generated_content' => $this->build_curation_story_content($title, $sources),
            'curation_mode' => $curation_mode,
            'curation_error' => $curation_error,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function build_curation_source_reference(array $item): array
    {
        return [
            'workflow_item_id' => (int) ($item['id'] ?? 0),
            'collector_item_id' => (int) ($item['collector_item_id'] ?? 0),
            'title' => (string) ($item['title'] ?? ''),
            'source_name' => (string) ($item['source_name'] ?? ''),
            'content_url' => (string) ($item['content_url'] ?? ''),
            'published_at' => (string) ($item['published_at'] ?? ''),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     */
    private function build_curation_story_content(string $title, array $sources): string
    {
        $html = [];
        $sources = $this->merge_curation_sources($sources);

        if ($sources !== []) {
            $html[] = '<div class="editorio-publisher__story-sources">';
            $html[] = '<strong>' . esc_html__('Fontes citadas:', 'editorio') . '</strong>';
            $html[] = '<ul>';

            foreach ($sources as $source) {
                $source_name = trim((string) ($source['source_name'] ?? ''));
                $source_title = trim((string) ($source['title'] ?? ''));
                $content_url = trim((string) ($source['content_url'] ?? ''));
                $label = $source_name !== '' ? $source_name : __('Fonte', 'editorio');

                $source_line = esc_html($label);
                if ($source_title !== '') {
                    $source_line .= ' - ' . esc_html($source_title);
                }

                if ($content_url !== '') {
                    $source_line = '<a href="' . esc_url($content_url) . '" target="_blank" rel="noreferrer">' . $source_line . '</a>';
                }

                $html[] = '<li>' . $source_line . '</li>';
            }

            $html[] = '</ul>';
            $html[] = '</div>';
        }

        return implode("\n", $html);
    }

    private function normalize_curation_key(string $value): string
    {
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;

        return trim($value);
    }

    private function build_curation_instruction(string $editorial_note = ''): string
    {
        $instruction = <<<TXT
You are an editorial news curator for a Portuguese-language newsroom.

Mandatory behavior:
- Group related input items into new synthesized editorial stories.
- Never repeat the original headlines verbatim.
- If multiple items cover the same event or topic, merge them into one new story.
- Each story must cite the source item ids used.
- Prefer 1 to 3 source items per story.
- Keep titles concise, neutral, and original.
- Prefer more recent articles first when choosing between otherwise similar stories.
- If publication dates differ, choose the newest and most recent coverage.
- Return only valid JSON. No markdown. No extra commentary.

Output schema:
{
  "stories": [
    {
      "title": "New synthesized headline",
      "source_ids": [1, 2, 3]
    }
  ]
}
TXT;

        $editorial_note = trim($editorial_note);
        if ($editorial_note !== '') {
            $instruction .= "\n\nEditorial note for tone or preferences only. Do not change the output schema or ask for raw selected ids:\n" . $editorial_note;
        }

        return $instruction;
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

    /**
     * @return array<string,mixed>
     */
    private function decode_json_response(string $response): array
    {
        $response = trim($response);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response) ?? $response;
        $response = preg_replace('/\s*```$/', '', $response) ?? $response;

        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    /**
     * @param array<int,array<string,mixed>> $stories
     * @return array<int,array<string,mixed>>
     */
    private function merge_similar_curation_stories(array $stories): array
    {
        $stories = array_values(array_filter($stories, static fn ($story): bool => is_array($story)));
        if ($stories === []) {
            return [];
        }

        $merged = [];

        foreach ($stories as $story) {
            $merged_into_existing = false;

            foreach ($merged as $index => $existing) {
                if (! $this->are_curation_stories_related($existing, $story)) {
                    continue;
                }

                $merged[$index] = $this->merge_curation_story_records($existing, $story);
                $merged_into_existing = true;
                break;
            }

            if (! $merged_into_existing) {
                $merged[] = $story;
            }
        }

        return array_values($merged);
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function are_curation_stories_related(array $left, array $right): bool
    {
        $left_title = trim((string) ($left['title'] ?? ''));
        $right_title = trim((string) ($right['title'] ?? ''));

        if ($left_title === '' || $right_title === '') {
            return false;
        }

        $left_key = $this->normalize_curation_key($left_title);
        $right_key = $this->normalize_curation_key($right_title);

        if ($left_key !== '' && $left_key === $right_key) {
            return true;
        }

        if ($left_key !== '' && $right_key !== '' && (str_contains($left_key, $right_key) || str_contains($right_key, $left_key))) {
            return true;
        }

        $left_source_ids = array_values(array_filter(array_map('intval', $left['source_ids'] ?? [])));
        $right_source_ids = array_values(array_filter(array_map('intval', $right['source_ids'] ?? [])));
        if ($left_source_ids !== [] && $right_source_ids !== [] && array_intersect($left_source_ids, $right_source_ids) !== []) {
            return true;
        }

        $left_tokens = $this->extract_curation_tokens($left_title . ' ' . (string) ($left['reason'] ?? ''));
        $right_tokens = $this->extract_curation_tokens($right_title . ' ' . (string) ($right['reason'] ?? ''));

        if ($left_tokens === [] || $right_tokens === []) {
            return false;
        }

        $intersection = array_values(array_intersect($left_tokens, $right_tokens));
        if ($intersection === []) {
            return false;
        }

        $coverage = count($intersection) / max(1, min(count($left_tokens), count($right_tokens)));
        if (count($intersection) >= 2 && $coverage >= 0.5) {
            return true;
        }

        $similarity = 0.0;
        similar_text($left_key !== '' ? $left_key : $left_title, $right_key !== '' ? $right_key : $right_title, $similarity);

        return $similarity >= 78.0;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private function merge_curation_story_records(array $left, array $right): array
    {
        $left_source_ids = array_values(array_filter(array_map('intval', $left['source_ids'] ?? [])));
        $right_source_ids = array_values(array_filter(array_map('intval', $right['source_ids'] ?? [])));
        $source_ids = array_values(array_unique(array_merge($left_source_ids, $right_source_ids)));

        $left_sources = is_array($left['sources'] ?? null) ? $left['sources'] : [];
        $right_sources = is_array($right['sources'] ?? null) ? $right['sources'] : [];
        $sources = $this->merge_curation_sources(array_merge($left_sources, $right_sources));

        $title = $this->choose_curation_title($left, $right);

        $representative_item_id = (int) ($left['representative_item_id'] ?? 0);
        if ($representative_item_id <= 0) {
            $representative_item_id = (int) ($right['representative_item_id'] ?? 0);
        }
        if ($representative_item_id <= 0 && $sources !== []) {
            $representative_item_id = (int) ($sources[0]['collector_item_id'] ?? 0);
        }

        $curation_mode = (string) ($left['curation_mode'] ?? $right['curation_mode'] ?? 'ai');
        if ($curation_mode !== 'automatic' && $curation_mode !== 'ai') {
            $curation_mode = 'ai';
        }

        $curation_error = trim((string) ($left['curation_error'] ?? ''));
        if ($curation_error === '') {
            $curation_error = trim((string) ($right['curation_error'] ?? ''));
        }

        return [
            'title' => $title,
            'reason' => '',
            'source_ids' => $source_ids,
            'sources' => $sources,
            'representative_item_id' => $representative_item_id,
            'generated_content' => $this->build_curation_story_content($title, $sources),
            'curation_mode' => $curation_mode,
            'curation_error' => $curation_error,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $stories
     * @return array<int,array<string,mixed>>
     */
    private function decorate_curation_stories(array $stories, string $curation_mode, string $curation_error): array
    {
        return array_map(
            static function (array $story) use ($curation_mode, $curation_error): array {
                $story['curation_mode'] = $curation_mode;
                $story['curation_error'] = $curation_error;
                return $story;
            },
            $stories
        );
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @return array<int,array<string,mixed>>
     */
    private function merge_curation_sources(array $sources): array
    {
        $merged = [];
        $seen = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $key = $this->build_curation_source_key($source);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $source;
        }

        return $merged;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function sort_curation_items_by_recent_first(array $items): array
    {
        usort(
            $items,
            static function (array $left, array $right): int {
                $left_time = strtotime((string) ($left['published_at'] ?? '')) ?: 0;
                $right_time = strtotime((string) ($right['published_at'] ?? '')) ?: 0;

                if ($left_time === $right_time) {
                    return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
                }

                return $right_time <=> $left_time;
            }
        );

        return array_values($items);
    }

    /**
     * @param array<string,mixed> $source
     */
    private function build_curation_source_key(array $source): string
    {
        $content_url = trim((string) ($source['content_url'] ?? ''));
        if ($content_url !== '') {
            $content_url = function_exists('mb_strtolower') ? mb_strtolower($content_url) : strtolower($content_url);
            return 'u:' . $content_url;
        }

        $source_name = $this->normalize_curation_key((string) ($source['source_name'] ?? ''));
        $title = $this->normalize_curation_key((string) ($source['title'] ?? ''));

        if ($source_name !== '' || $title !== '') {
            return 't:' . $source_name . '|' . $title;
        }

        $workflow_item_id = (int) ($source['workflow_item_id'] ?? 0);
        $collector_item_id = (int) ($source['collector_item_id'] ?? 0);

        return $workflow_item_id > 0 ? 'w:' . $workflow_item_id : 'c:' . $collector_item_id;
    }

    /**
     * @param string ...$reasons
     */
    private function combine_curation_reasons(string ...$reasons): string
    {
        $reasons = array_values(array_filter(array_map('trim', $reasons)));
        if ($reasons === []) {
            return '';
        }

        $reasons = array_values(array_unique($reasons));
        return implode(' ', $reasons);
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function choose_curation_title(array $left, array $right): string
    {
        $left_title = trim((string) ($left['title'] ?? ''));
        $right_title = trim((string) ($right['title'] ?? ''));

        if ($left_title === '') {
            return $right_title;
        }

        if ($right_title === '') {
            return $left_title;
        }

        $left_score = count($this->extract_curation_tokens($left_title));
        $right_score = count($this->extract_curation_tokens($right_title));

        if ($left_score === $right_score) {
            return mb_strlen($left_title) >= mb_strlen($right_title) ? $left_title : $right_title;
        }

        return $left_score > $right_score ? $left_title : $right_title;
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     */
    private function build_curation_title_from_sources(array $sources): string
    {
        $source_titles = [];
        $token_sets = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $title = trim((string) ($source['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $tokens = $this->extract_curation_tokens($title);
            if ($tokens === []) {
                continue;
            }

            $source_titles[] = $title;
            $token_sets[] = $tokens;
        }

        if ($source_titles === []) {
            return '';
        }

        $common_prefix = $token_sets[0];
        foreach ($token_sets as $tokens) {
            $common_prefix = $this->common_curation_token_prefix($common_prefix, $tokens);
            if ($common_prefix === []) {
                break;
            }
        }

        $selected_tokens = $common_prefix;
        if (count($selected_tokens) < 2) {
            $token_counts = [];

            foreach ($token_sets as $tokens) {
                foreach (array_values(array_unique($tokens)) as $token) {
                    $token_counts[$token] = ($token_counts[$token] ?? 0) + 1;
                }
            }

            arsort($token_counts);

            foreach ($token_counts as $token => $count) {
                if ($count < 2 && count($selected_tokens) >= 2) {
                    break;
                }

                if (! in_array($token, $selected_tokens, true)) {
                    $selected_tokens[] = $token;
                }

                if (count($selected_tokens) >= 4) {
                    break;
                }
            }
        }

        if ($selected_tokens === []) {
            return $source_titles[0];
        }

        return implode(' ', array_map(
            static fn (string $token): string => function_exists('mb_convert_case')
                ? mb_convert_case($token, MB_CASE_TITLE, 'UTF-8')
                : ucfirst($token),
            $selected_tokens
        ));
    }

    /**
     * @param array<int,string> $left
     * @param array<int,string> $right
     * @return array<int,string>
     */
    private function common_curation_token_prefix(array $left, array $right): array
    {
        $prefix = [];
        $max = min(count($left), count($right));

        for ($index = 0; $index < $max; $index++) {
            if ($left[$index] !== $right[$index]) {
                break;
            }

            $prefix[] = $left[$index];
        }

        return $prefix;
    }

    /**
     * @return array<int,string>
     */
    private function extract_curation_tokens(string $value): array
    {
        $value = $this->normalize_curation_key($value);
        if ($value === '') {
            return [];
        }

        $stopwords = [
            'a', 'as', 'ao', 'aos', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos',
            'o', 'os', 'para', 'por', 'com', 'sem', 'um', 'uma', 'uns', 'umas', 'que', 'sobre',
            'the', 'and', 'for', 'with', 'from', 'by', 'to', 'of', 'in',
        ];

        $tokens = preg_split('/\s+/', $value) ?: [];
        $tokens = array_values(array_filter($tokens, static function (string $token) use ($stopwords): bool {
            if ($token === '' || strlen($token) < 3) {
                return false;
            }

            if (ctype_digit($token)) {
                return false;
            }

            return ! in_array($token, $stopwords, true);
        }));

        return $tokens;
    }

    private function build_curation_topic_key(string $value): string
    {
        $tokens = $this->extract_curation_tokens($value);
        if ($tokens === []) {
            return '';
        }

        return implode(' ', array_slice($tokens, 0, 4));
    }
}
