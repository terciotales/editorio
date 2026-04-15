<?php

declare(strict_types=1);

namespace Editorio\Modules\Sources\Repository;

final class SourcesRepository
{
    private const TABLE_SUFFIX = 'editorio_sources';

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
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            feed_url VARCHAR(2083) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY is_active (is_active)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    public function count(): int
    {
        global $wpdb;

        $table_name = $this->get_table_name();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $where = [];
        $params = [];

        if (array_key_exists('is_active', $filters)) {
            $where[] = 'is_active = %d';
            $params[] = (int) $filters['is_active'];
        }

        $sql = "SELECT id, name, feed_url, is_active, created_at, updated_at FROM {$table_name}";
        if (! empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';

        if (! empty($params)) {
            $prepared = $wpdb->prepare($sql, $params);
            if (! is_string($prepared)) {
                return [];
            }

            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } else {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }

        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'map_row'], $rows);
    }

    public function get_by_id(int $id): ?array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $prepared = $wpdb->prepare(
            "SELECT id, name, feed_url, is_active, created_at, updated_at FROM {$table_name} WHERE id = %d LIMIT 1",
            $id
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

    public function exists_by_feed_url(string $feed_url, ?int $exclude_id = null): bool
    {
        global $wpdb;

        $table_name = $this->get_table_name();

        if ($exclude_id !== null) {
            $prepared = $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE feed_url = %s AND id != %d LIMIT 1",
                $feed_url,
                $exclude_id
            );
        } else {
            $prepared = $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE feed_url = %s LIMIT 1",
                $feed_url
            );
        }

        if (! is_string($prepared)) {
            return false;
        }

        $value = $wpdb->get_var($prepared);

        return $value !== null;
    }

    public function create(array $data): ?array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $table_name,
            [
                'name' => (string) $data['name'],
                'feed_url' => (string) $data['feed_url'],
                'is_active' => (int) $data['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            return null;
        }

        return $this->get_by_id((int) $wpdb->insert_id);
    }

    public function update(int $id, array $data): ?array
    {
        global $wpdb;

        $table_name = $this->get_table_name();

        $updated = $wpdb->update(
            $table_name,
            [
                'name' => (string) $data['name'],
                'feed_url' => (string) $data['feed_url'],
                'is_active' => (int) $data['is_active'],
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return null;
        }

        return $this->get_by_id($id);
    }

    public function delete(int $id): bool
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $deleted = $wpdb->delete($table_name, ['id' => $id], ['%d']);

        return $deleted !== false;
    }

    private function map_row(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'feed_url' => (string) ($row['feed_url'] ?? ''),
            'is_active' => (bool) ((int) ($row['is_active'] ?? 0)),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
