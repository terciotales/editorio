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
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function generate_review_draft(array $payload): array
    {
        $content = trim((string) ($payload['content'] ?? ''));
        $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];
        $current_draft = is_array($payload['current_draft'] ?? null) ? $payload['current_draft'] : [];
        $categories = is_array($payload['categories'] ?? null) ? $payload['categories'] : [];
        $sources = is_array($item['sources'] ?? null) ? $item['sources'] : [];
        $field = sanitize_key((string) ($payload['field'] ?? 'all'));
        $allowed_fields = ['all', 'title', 'summary', 'categories', 'tags', 'content'];
        if (! in_array($field, $allowed_fields, true)) {
            $field = 'all';
        }

        $backend_source_content = $this->build_review_source_context($sources);
        if ($backend_source_content !== '') {
            $content = trim($content . "\n\n=== SOURCE PAGES FETCHED BY SERVER ===\n" . $backend_source_content);
        }

        if ($content === '') {
            $content = trim(
                (string) ($item['title'] ?? '') . "\n\n" .
                (string) ($item['summary'] ?? '') . "\n\n" .
                wp_json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

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

        $prompt = wp_json_encode([
            'task' => 'Generate a review-ready rewritten news article draft from source material.',
            'current_date' => current_time('mysql'),
            'regenerate_field' => $field,
            'story' => [
                'title' => (string) ($item['title'] ?? ''),
                'summary' => (string) ($item['summary'] ?? ''),
                'sources' => $sources,
            ],
            'available_categories' => $this->normalize_review_categories($categories),
            'current_draft' => $current_draft,
            'source_content' => function_exists('mb_substr') ? mb_substr($content, 0, 24000) : substr($content, 0, 24000),
            'output_format' => [
                'title' => 'Original rewritten headline',
                'summary' => 'Short deck/summary',
                'categories_suggested' => ['Existing category name'],
                'tags_suggested' => ['tag one', 'tag two'],
                'content' => '<p>Rewritten article body in HTML.</p>',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($prompt) || $prompt === '') {
            return [
                'success' => false,
                'error' => __('Could not build AI prompt.', 'editorio'),
            ];
        }

        $system_instruction = $this->build_review_draft_instruction((string) ($settings['rewrite_prompt'] ?? ''));

        try {
            $response = get_ai_service()->create_textgen_prompt($prompt, [
                'system_instruction' => $system_instruction,
            ])->generate_text();
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }

        if (! is_string($response) || $response === '') {
            return [
                'success' => false,
                'error' => __('AI provider returned an empty response.', 'editorio'),
            ];
        }

        $decoded = $this->decode_json_response($response);
        if ($decoded === []) {
            $parsed = $this->parse_response($response);
            $decoded = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
        }

        $draft = $this->normalize_review_draft($decoded, $item, $current_draft);
        if ($field !== 'all') {
            $draft = array_merge($this->normalize_review_draft($current_draft, $item, []), [
                $this->map_review_field_to_key($field) => $draft[$this->map_review_field_to_key($field)] ?? '',
            ]);
        }

        return [
            'success' => true,
            'draft' => $draft,
            'raw' => $response,
        ];
    }

    private function build_review_draft_instruction(string $editorial_note = ''): string
    {
        $instruction = <<<TXT
You are a Portuguese-language newsroom editor.

Rewrite source material into an original news article.
Keep factual accuracy. Do not copy source phrasing.
Use neutral journalistic tone.
Use the source_content as the primary factual context.
Do not invent facts, schedules, broadcast channels, rankings, lineups, quotes, venues, dates, times, scores, or consequences.
Only mention match time, broadcast information, probable lineups, table position, venue, or competition details when those exact facts appear in source_content or story.sources.
If a detail is not present in the provided sources, omit it instead of writing generic filler such as "com opções indicadas", "deve ser definido", or "pode ter impacto".
Prefer concrete facts extracted from the sources over generic background.
Return only valid JSON. No markdown. No commentary.

Required JSON keys:
- title: concise headline.
- summary: short article summary.
- categories_suggested: category names chosen only from available_categories.
- tags_suggested: short lowercase tags.
- content: complete rewritten article body in clean HTML paragraphs.

When regenerate_field is not "all", regenerate only that field and keep other current_draft fields stable.
TXT;

        $editorial_note = trim($editorial_note);
        if ($editorial_note !== '') {
            $instruction .= "\n\nEditorial rewrite instructions:\n" . $editorial_note;
        }

        return $instruction;
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     */
    private function build_review_source_context(array $sources): string
    {
        $chunks = [];

        foreach (array_slice($sources, 0, 4) as $index => $source) {
            if (! is_array($source)) {
                continue;
            }

            $title = trim((string) ($source['title'] ?? ''));
            $source_name = trim((string) ($source['source_name'] ?? ''));
            $summary = trim((string) ($source['summary'] ?? ''));
            $url = trim((string) ($source['content_url'] ?? ''));
            $fetched_text = $this->fetch_review_source_text($url);

            $parts = array_filter([
                'Source #' . ((int) $index + 1),
                $source_name !== '' ? 'Publisher: ' . $source_name : '',
                $title !== '' ? 'Title: ' . $title : '',
                $summary !== '' ? 'Summary: ' . $summary : '',
                $url !== '' ? 'URL: ' . $url : '',
                $fetched_text !== '' ? "Fetched page text:\n" . $fetched_text : '',
            ]);

            if ($parts !== []) {
                $chunks[] = implode("\n", $parts);
            }
        }

        $context = implode("\n\n---\n\n", $chunks);

        return function_exists('mb_substr') ? mb_substr($context, 0, 24000) : substr($context, 0, 24000);
    }

    private function fetch_review_source_text(string $url): string
    {
        if ($url === '' || ! wp_http_validate_url($url)) {
            return '';
        }

        $response = wp_remote_get($url, [
            'timeout' => 8,
            'redirection' => 3,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; EditorioBot/1.0; +https://wordpress.org/)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return '';
        }

        return $this->extract_review_text_from_html((string) wp_remote_retrieve_body($response));
    }

    private function extract_review_text_from_html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = preg_replace('/<(script|style|noscript|svg|iframe)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = wp_strip_all_tags($html, true);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        return function_exists('mb_substr') ? mb_substr($text, 0, 8000) : substr($text, 0, 8000);
    }

    /**
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,array{id:int,name:string}>
     */
    private function normalize_review_categories(array $categories): array
    {
        $normalized = [];

        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }

            $name = trim((string) ($category['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'id' => (int) ($category['id'] ?? 0),
                'name' => $name,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $item
     * @param array<string,mixed> $fallback
     * @return array{title:string,summary:string,categories_suggested:array<int,string>,tags_suggested:array<int,string>,content:string}
     */
    private function normalize_review_draft(array $draft, array $item, array $fallback): array
    {
        $title = trim((string) ($draft['title'] ?? $fallback['title'] ?? $item['generated_title'] ?? $item['title'] ?? ''));
        $summary = trim((string) ($draft['summary'] ?? $fallback['summary'] ?? $item['summary'] ?? $item['curation_reason'] ?? ''));
        $content = trim((string) ($draft['content'] ?? $fallback['content'] ?? $item['generated_content'] ?? ''));

        return [
            'title' => $title !== '' ? $title : __('Untitled', 'editorio'),
            'summary' => $summary,
            'categories_suggested' => $this->normalize_string_list($draft['categories_suggested'] ?? $fallback['categories_suggested'] ?? []),
            'tags_suggested' => $this->normalize_string_list($draft['tags_suggested'] ?? $fallback['tags_suggested'] ?? []),
            'content' => wp_kses_post($content),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function normalize_string_list(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\n]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }

    private function map_review_field_to_key(string $field): string
    {
        return match ($field) {
            'categories' => 'categories_suggested',
            'tags' => 'tags_suggested',
            default => $field,
        };
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
            return $this->build_curation_fallback(
                $items,
                $limit,
                __('Could not build the AI curation prompt.', 'editorio')
            );
        }

        $system_instruction = $this->build_curation_instruction((string) ($settings['curation_prompt'] ?? ''));

        try {
            $response = get_ai_service()->create_textgen_prompt($prompt, [
                'system_instruction' => $system_instruction,
            ])->generate_text();
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
            'summary' => (string) ($item['summary'] ?? ''),
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
