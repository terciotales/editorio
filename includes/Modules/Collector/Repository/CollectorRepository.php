<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Repository;

final class CollectorRepository
{
    private const TABLE_SUFFIX = 'editorio_collector_items';

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
            source_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(191) NOT NULL,
            description LONGTEXT,
            content_url VARCHAR(2083) NOT NULL,
            image_url VARCHAR(2083),
            author VARCHAR(191),
            published_at DATETIME,
            collected_at DATETIME NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'collected',
            hash VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY source_id (source_id),
            KEY status (status),
            KEY hash (hash),
            KEY collected_at (collected_at)
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
            "SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
            $status
        );

        if (!is_string($prepared)) {
            return 0;
        }

        return (int) $wpdb->get_var($prepared);
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

        if (array_key_exists('source_id', $filters) && $filters['source_id'] !== null) {
            $where[] = 'source_id = %d';
            $params[] = (int) $filters['source_id'];
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== null) {
            $where[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }

        if (array_key_exists('search', $filters) && $filters['search'] !== null) {
            $where[] = '(title LIKE %s OR description LIKE %s)';
            $search = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT id, source_id, title, description, content_url, image_url, author, published_at, collected_at, status, hash, created_at, updated_at FROM {$table_name}";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY collected_at DESC';

        if (!empty($params)) {
            $prepared = $wpdb->prepare($sql, $params);
            if (!is_string($prepared)) {
                return [];
            }

            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } else {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }

        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'map_row'], $rows);
    }

    public function get_by_id(int $id): ?array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $prepared = $wpdb->prepare(
            "SELECT id, source_id, title, description, content_url, image_url, author, published_at, collected_at, status, hash, created_at, updated_at FROM {$table_name} WHERE id = %d LIMIT 1",
            $id
        );

        if (!is_string($prepared)) {
            return null;
        }

        $row = $wpdb->get_row($prepared, ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        return $this->map_row($row);
    }

    public function get_by_hash(int $source_id, string $hash): ?array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $prepared = $wpdb->prepare(
            "SELECT id, source_id, title, description, content_url, image_url, author, published_at, collected_at, status, hash, created_at, updated_at FROM {$table_name} WHERE source_id = %d AND hash = %s LIMIT 1",
            $source_id,
            $hash
        );

        if (!is_string($prepared)) {
            return null;
        }

        $row = $wpdb->get_row($prepared, ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        return $this->map_row($row);
    }

    public function create(array $data): ?array
    {
        global $wpdb;

        $table_name = $this->get_table_name();
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $table_name,
            [
                'source_id' => (int) $data['source_id'],
                'title' => (string) $data['title'],
                'description' => (string) ($data['description'] ?? ''),
                'content_url' => (string) $data['content_url'],
                'image_url' => (string) ($data['image_url'] ?? ''),
                'author' => (string) ($data['author'] ?? ''),
                'published_at' => (string) ($data['published_at'] ?? ''),
                'collected_at' => $now,
                'status' => (string) ($data['status'] ?? 'collected'),
                'hash' => (string) $data['hash'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
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

        $update_data = [
            'updated_at' => current_time('mysql'),
        ];
        $update_format = ['%s'];

        if (array_key_exists('status', $data)) {
            $update_data['status'] = (string) $data['status'];
            $update_format[] = '%s';
        }

        if (array_key_exists('title', $data)) {
            $update_data['title'] = (string) $data['title'];
            $update_format[] = '%s';
        }

        if (array_key_exists('description', $data)) {
            $update_data['description'] = (string) $data['description'];
            $update_format[] = '%s';
        }

        $updated = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $id],
            $update_format,
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
            'source_id' => (int) ($row['source_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'content_url' => (string) ($row['content_url'] ?? ''),
            'image_url' => (string) ($row['image_url'] ?? ''),
            'author' => (string) ($row['author'] ?? ''),
            'published_at' => (string) ($row['published_at'] ?? ''),
            'collected_at' => (string) ($row['collected_at'] ?? ''),
            'status' => (string) ($row['status'] ?? 'collected'),
            'hash' => (string) ($row['hash'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}

