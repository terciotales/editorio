<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher\Repository;

use wpdb;

final class PublisherRepository
{
    private wpdb $wpdb;
    private string $table_sessions;
    private string $table_items;
    private static bool $items_schema_checked = false;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_sessions = $wpdb->prefix . 'editorio_workflow_sessions';
        $this->table_items = $wpdb->prefix . 'editorio_workflow_items';
    }

    public function install(): void
    {
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$this->table_sessions} (
            id VARCHAR(64) PRIMARY KEY,
            user_id BIGINT NOT NULL,
            stage VARCHAR(50) NOT NULL DEFAULT 'collecting',
            collected_count INT DEFAULT 0,
            curated_count INT DEFAULT 0,
            selected_count INT DEFAULT 0,
            approved_count INT DEFAULT 0,
            rejected_count INT DEFAULT 0,
            data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY user_id (user_id),
            KEY stage (stage)
        ) {$charset_collate};";

        $sql_items = "CREATE TABLE IF NOT EXISTS {$this->table_items} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL,
            collector_item_id BIGINT NOT NULL,
            is_curated BOOLEAN DEFAULT FALSE,
            curation_reason LONGTEXT NULL,
            curation_sources LONGTEXT NULL,
            is_selected BOOLEAN DEFAULT FALSE,
            approval_status VARCHAR(20) DEFAULT NULL,
            generated_content LONGTEXT,
            generated_title VARCHAR(255),
            generated_summary LONGTEXT,
            generated_categories LONGTEXT,
            generated_tags LONGTEXT,
            featured_image_id BIGINT NULL,
            featured_image_url LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY session_id (session_id),
            KEY collector_item_id (collector_item_id),
            KEY approval_status (approval_status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_sessions);
        dbDelta($sql_items);
    }

    public function create_session(string $user_id): string
    {
        $session_id = 'wf_' . wp_generate_uuid4();
        
        $this->wpdb->insert(
            $this->table_sessions,
            [
                'id' => $session_id,
                'user_id' => (int) $user_id,
                'stage' => 'collecting',
                'data' => wp_json_encode([]),
            ],
            ['%s', '%d', '%s', '%s']
        );

        return $session_id;
    }

    public function get_session(string $session_id): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_sessions} WHERE id = %s",
                $session_id
            ),
            ARRAY_A
        );

        if (!$result) {
            return null;
        }

        $result['data'] = json_decode($result['data'] ?? '{}', true);
        return $result;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list_recent_sessions(int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));

        $sessions = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT *
                FROM {$this->table_sessions}
                ORDER BY updated_at DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];

        return array_map(
            static function (array $session): array {
                $session['data'] = json_decode($session['data'] ?? '{}', true);
                return $session;
            },
            $sessions
        );
    }

    public function update_session(string $session_id, array $data): bool
    {
        return (bool) $this->wpdb->update(
            $this->table_sessions,
            $data,
            ['id' => $session_id],
            array_fill(0, count($data), '%s'),
            ['%s']
        );
    }

    public function update_stage(string $session_id, string $stage): bool
    {
        return (bool) $this->wpdb->update(
            $this->table_sessions,
            ['stage' => $stage],
            ['id' => $session_id],
            ['%s'],
            ['%s']
        );
    }

    public function add_items(string $session_id, array $collector_items): void
    {
        foreach ($collector_items as $item) {
            $this->wpdb->insert(
                $this->table_items,
                [
                    'session_id' => $session_id,
                    'collector_item_id' => (int) $item['id'],
                    'is_curated' => false,
                ],
                ['%s', '%d', '%d']
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_session_items(string $session_id, bool $curated_only = false): array
    {
        $this->ensure_items_schema();
        $condition = $curated_only ? 'AND wi.is_curated = TRUE' : '';

        $items = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    wi.id,
                    wi.session_id,
                    wi.collector_item_id,
                    wi.is_curated,
                    wi.is_selected,
                    wi.approval_status,
                    wi.curation_reason,
                    wi.generated_title,
                    wi.generated_summary,
                    wi.generated_categories,
                    wi.generated_tags,
                    wi.featured_image_id,
                    wi.featured_image_url,
                    wi.generated_content,
                    wi.curation_sources,
                    ci.source_id,
                    ci.source_type,
                    ci.external_id,
                    ci.title,
                    ci.summary,
                    ci.content_url,
                    ci.published_at,
                    s.name AS source_name,
                    s.feed_url AS source_feed_url
                FROM {$this->table_items} wi
                INNER JOIN {$this->wpdb->prefix}editorio_collector_items ci ON ci.id = wi.collector_item_id
                LEFT JOIN {$this->wpdb->prefix}editorio_sources s ON s.id = ci.source_id
                WHERE wi.session_id = %s {$condition}
                ORDER BY wi.id ASC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];

        return array_map(
            static function (array $item): array {
                $sources = $item['curation_sources'] ?? '';
                if (is_string($sources) && $sources !== '') {
                    $decoded_sources = json_decode($sources, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_sources)) {
                        $item['curation_sources'] = $decoded_sources;
                    }
                }

                return $item;
            },
            $items
        );
    }

    /**
     * @param array<int,array<string,mixed>|int> $items
     */
    public function mark_curated(string $session_id, array $items): void
    {
        foreach ($items as $item) {
            $item_id = (int) (is_array($item) ? ($item['id'] ?? 0) : $item);
            $curation_reason = is_array($item) ? (string) ($item['curation_reason'] ?? '') : '';

            $this->wpdb->update(
                $this->table_items,
                [
                    'is_curated' => true,
                    'curation_reason' => $curation_reason,
                ],
                ['session_id' => $session_id, 'id' => (int) $item_id],
                ['%d', '%s'],
                ['%s', '%d']
            );
        }

        $curated_count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_items} WHERE session_id = %s AND is_curated = TRUE",
                $session_id
            )
        );

        $this->wpdb->update(
            $this->table_sessions,
            ['curated_count' => (int) $curated_count],
            ['id' => $session_id],
            ['%d'],
            ['%s']
        );
    }

    /**
     * @param array<int,array<string,mixed>> $stories
     */
    public function add_curated_stories(string $session_id, array $stories): void
    {
        $inserted = 0;

        foreach ($stories as $story) {
            $representative_item_id = (int) ($story['representative_item_id'] ?? 0);
            if ($representative_item_id <= 0) {
                continue;
            }

            $result = $this->wpdb->insert(
                $this->table_items,
                [
                    'session_id' => $session_id,
                    'collector_item_id' => $representative_item_id,
                    'is_curated' => true,
                    'curation_reason' => (string) ($story['reason'] ?? ''),
                    'curation_sources' => wp_json_encode($story['sources'] ?? []),
                    'generated_title' => (string) ($story['title'] ?? ''),
                    'generated_content' => (string) ($story['generated_content'] ?? ''),
                ],
                ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
            );

            if ($result !== false) {
                $inserted++;
            }
        }

        $curated_count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_items} WHERE session_id = %s AND is_curated = TRUE",
                $session_id
            )
        );

        $this->wpdb->update(
            $this->table_sessions,
            ['curated_count' => (int) $curated_count],
            ['id' => $session_id],
            ['%d'],
            ['%s']
        );
    }

    /**
     * @param array<int,array<string,mixed>> $stories
     */
    public function replace_curated_stories(string $session_id, array $stories): void
    {
        $this->wpdb->delete(
            $this->table_items,
            [
                'session_id' => $session_id,
                'is_curated' => 1,
            ],
            ['%s', '%d']
        );

        $this->add_curated_stories($session_id, $stories);
    }

    public function mark_selected(string $session_id, array $item_ids): void
    {
        $this->wpdb->update(
            $this->table_items,
            ['is_selected' => false],
            ['session_id' => $session_id],
            ['%d'],
            ['%s']
        );

        foreach ($item_ids as $item_id) {
            $this->wpdb->update(
                $this->table_items,
                ['is_selected' => true],
                ['session_id' => $session_id, 'id' => (int) $item_id],
                ['%d'],
                ['%s', '%d']
            );
        }

        $selected_count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_items} WHERE session_id = %s AND is_selected = TRUE",
                $session_id
            )
        );

        $this->wpdb->update(
            $this->table_sessions,
            ['selected_count' => (int) $selected_count],
            ['id' => $session_id],
            ['%d'],
            ['%s']
        );
    }

    public function get_curated_items(string $session_id): array
    {
        return $this->get_session_items($session_id, true);
    }

    public function get_selected_items(string $session_id): array
    {
        return array_values(array_filter(
            $this->get_session_items($session_id, true),
            static fn (array $item): bool => (int) ($item['is_selected'] ?? 0) === 1
        ));
    }

    public function reject_pending_selected_items(string $session_id): int
    {
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table_items}
                SET approval_status = %s
                WHERE session_id = %s
                  AND is_selected = 1
                  AND (approval_status IS NULL OR approval_status = '')",
                'rejected',
                $session_id
            )
        );

        $approved = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_items} WHERE session_id = %s AND approval_status = %s",
                $session_id,
                'approved'
            )
        );

        $rejected = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_items} WHERE session_id = %s AND approval_status = %s",
                $session_id,
                'rejected'
            )
        );

        $this->wpdb->update(
            $this->table_sessions,
            ['approved_count' => (int) $approved, 'rejected_count' => (int) $rejected],
            ['id' => $session_id],
            ['%d', '%d'],
            ['%s']
        );

        return max(0, (int) $updated);
    }

    /**
     * @param array<int,string> $generated_categories
     * @param array<int,string> $generated_tags
     */
    public function update_item_approval(
        string $session_id,
        int $item_id,
        string $status,
        string $generated_title = '',
        string $generated_content = '',
        string $generated_summary = '',
        array $generated_categories = [],
        array $generated_tags = [],
        int $featured_image_id = 0,
        string $featured_image_url = ''
    ): void
    {
        $this->ensure_items_schema();
        $this->wpdb->update(
            $this->table_items,
            [
                'approval_status' => $status,
                'generated_title' => $generated_title,
                'generated_content' => $generated_content,
                'generated_summary' => $generated_summary,
                'generated_categories' => wp_json_encode(array_values($generated_categories)),
                'generated_tags' => wp_json_encode(array_values($generated_tags)),
                'featured_image_id' => $featured_image_id > 0 ? $featured_image_id : null,
                'featured_image_url' => $featured_image_url,
            ],
            ['session_id' => $session_id, 'id' => $item_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
            ['%s', '%d']
        );

        $approved = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_items} WHERE session_id = %s AND approval_status = %s",
                $session_id,
                'approved'
            )
        );

        $rejected = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_items} WHERE session_id = %s AND approval_status = %s",
                $session_id,
                'rejected'
            )
        );

        $this->wpdb->update(
            $this->table_sessions,
            ['approved_count' => (int) $approved, 'rejected_count' => (int) $rejected],
            ['id' => $session_id],
            ['%d', '%d'],
            ['%s']
        );
    }

    public function get_approval_summary(string $session_id): array
    {
        return $this->get_selected_items($session_id);
    }

    public function count(): int
    {
        return 0;
    }

    private function ensure_items_schema(): void
    {
        if (self::$items_schema_checked) {
            return;
        }

        $columns = [
            'generated_summary' => 'LONGTEXT',
            'generated_categories' => 'LONGTEXT',
            'generated_tags' => 'LONGTEXT',
            'featured_image_id' => 'BIGINT NULL',
            'featured_image_url' => 'LONGTEXT NULL',
        ];

        foreach ($columns as $column => $definition) {
            $existing = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SHOW COLUMNS FROM {$this->table_items} LIKE %s",
                    $column
                )
            );

            if ($existing === null) {
                $this->wpdb->query("ALTER TABLE {$this->table_items} ADD COLUMN {$column} {$definition} NULL AFTER generated_content");
            }
        }

        self::$items_schema_checked = true;
    }
}
