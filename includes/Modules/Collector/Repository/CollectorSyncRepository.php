<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Repository;

final class CollectorSyncRepository
{
    private const TABLE_SUFFIX = 'editorio_collector_sync_state';

    public function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public function create_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            source_id BIGINT UNSIGNED NOT NULL,
            feed_url VARCHAR(2083) NOT NULL,
            feed_type VARCHAR(20) NOT NULL DEFAULT 'xml',
            etag VARCHAR(255) DEFAULT '',
            last_modified VARCHAR(255) DEFAULT '',
            last_synced_at DATETIME DEFAULT NULL,
            last_status VARCHAR(50) NOT NULL DEFAULT 'idle',
            last_error LONGTEXT,
            items_collected_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (source_id),
            KEY last_status (last_status)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    public function count(): int
    {
        global $wpdb;

        $table_name = $this->get_table_name();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }

    public function count_by_status(string $status): int
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $prepared = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE last_status = %s",
            $status
        );

        if (! is_string($prepared)) {
            return 0;
        }

        return (int) $wpdb->get_var($prepared);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list_by_status(string $status, int $limit = 0): array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $sql = "SELECT source_id, feed_url, feed_type, etag, last_modified, last_synced_at, last_status, last_error, items_collected_total, created_at, updated_at FROM {$table_name} WHERE last_status = %s ORDER BY updated_at ASC";
        $params = [$status];

        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $params[] = $limit;
        }

        $prepared = $wpdb->prepare($sql, $params);
        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'map_row'], $rows);
    }

    public function get_by_source_id(int $source_id): ?array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $prepared = $wpdb->prepare(
            "SELECT source_id, feed_url, feed_type, etag, last_modified, last_synced_at, last_status, last_error, items_collected_total, created_at, updated_at FROM {$table_name} WHERE source_id = %d LIMIT 1",
            $source_id
        );

        if (! is_string($prepared)) {
            return null;
        }

        $row = $wpdb->get_row($prepared, ARRAY_A);
        if (! is_array($row)) {
            return null;
        }

        return $this->map_row($row);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function upsert(array $data): ?array
    {
        global $wpdb;

        $source_id = (int) ($data['source_id'] ?? 0);
        if ($source_id <= 0) {
            return null;
        }

        $existing = $this->get_by_source_id($source_id);
        $now = current_time('mysql');

        $payload = [
            'source_id' => $source_id,
            'feed_url' => (string) ($data['feed_url'] ?? ''),
            'feed_type' => (string) ($data['feed_type'] ?? 'xml'),
            'etag' => (string) ($data['etag'] ?? ''),
            'last_modified' => (string) ($data['last_modified'] ?? ''),
            'last_synced_at' => $data['last_synced_at'] !== null && $data['last_synced_at'] !== ''
                ? (string) $data['last_synced_at']
                : null,
            'last_status' => (string) ($data['last_status'] ?? 'ok'),
            'last_error' => (string) ($data['last_error'] ?? ''),
            'items_collected_total' => (int) ($data['items_collected_total'] ?? 0),
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $inserted = $wpdb->insert(
                $table_name = $this->get_table_name(),
                $payload + ['created_at' => $now],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );

            if ($inserted === false) {
                return null;
            }

            return $this->get_by_source_id($source_id);
        }

        $updated = $wpdb->update(
            $this->get_table_name(),
            [
                'feed_url' => $payload['feed_url'],
                'feed_type' => $payload['feed_type'],
                'etag' => $payload['etag'],
                'last_modified' => $payload['last_modified'],
                'last_synced_at' => $payload['last_synced_at'],
                'last_status' => $payload['last_status'],
                'last_error' => $payload['last_error'],
                'items_collected_total' => $payload['items_collected_total'],
                'updated_at' => $payload['updated_at'],
            ],
            ['source_id' => $source_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return null;
        }

        return $this->get_by_source_id($source_id);
    }

    private function map_row(array $row): array
    {
        return [
            'source_id' => (int) ($row['source_id'] ?? 0),
            'feed_url' => (string) ($row['feed_url'] ?? ''),
            'feed_type' => (string) ($row['feed_type'] ?? 'xml'),
            'etag' => (string) ($row['etag'] ?? ''),
            'last_modified' => (string) ($row['last_modified'] ?? ''),
            'last_synced_at' => (string) ($row['last_synced_at'] ?? ''),
            'last_status' => (string) ($row['last_status'] ?? 'idle'),
            'last_error' => (string) ($row['last_error'] ?? ''),
            'items_collected_total' => (int) ($row['items_collected_total'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
