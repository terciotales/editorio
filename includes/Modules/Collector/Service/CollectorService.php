<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Service;

use Editorio\Modules\Collector\Repository\CollectorRepository;
use WP_Error;

final class CollectorService
{
    private CollectorRepository $repository;

    public function __construct(CollectorRepository $repository)
    {
        $this->repository = $repository;
    }

    public function install(): void
    {
        $this->repository->create_table();
    }

    public function get_status(): array
    {
        return [
            'module' => 'collector',
            'status' => 'ok',
            'items' => $this->repository->count(),
            'counts' => [
                'collected' => $this->repository->count_by_status('collected'),
                'processing' => $this->repository->count_by_status('processing'),
                'processed' => $this->repository->count_by_status('processed'),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        return $this->repository->list($filters);
    }

    public function get(int $id): ?array
    {
        return $this->repository->get_by_id($id);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function create(array $payload)
    {
        $normalized = $this->normalize_payload($payload);
        if ($normalized instanceof WP_Error) {
            return $normalized;
        }

        // Check for duplicates
        $existing = $this->repository->get_by_hash(
            $normalized['source_id'],
            $normalized['hash']
        );
        if ($existing !== null) {
            return new WP_Error(
                'editorio_collector_duplicate',
                __('This item has already been collected.', 'editorio'),
                ['status' => 409]
            );
        }

        $created = $this->repository->create($normalized);
        if ($created === null) {
            return new WP_Error(
                'editorio_collector_create_failed',
                __('Could not create collector item.', 'editorio'),
                ['status' => 500]
            );
        }

        return $created;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function update_status(int $id, string $status)
    {
        $valid_statuses = ['collected', 'processing', 'processed'];
        if (!in_array($status, $valid_statuses, true)) {
            return new WP_Error(
                'editorio_collector_invalid_status',
                sprintf(__('Invalid status. Must be one of: %s', 'editorio'), implode(', ', $valid_statuses)),
                ['status' => 400]
            );
        }

        $existing = $this->repository->get_by_id($id);
        if ($existing === null) {
            return new WP_Error(
                'editorio_collector_not_found',
                __('Collector item not found.', 'editorio'),
                ['status' => 404]
            );
        }

        $updated = $this->repository->update($id, ['status' => $status]);
        if ($updated === null) {
            return new WP_Error(
                'editorio_collector_update_failed',
                __('Could not update collector item.', 'editorio'),
                ['status' => 500]
            );
        }

        return $updated;
    }

    /**
     * @return true|WP_Error
     */
    public function delete(int $id)
    {
        $existing = $this->repository->get_by_id($id);
        if ($existing === null) {
            return new WP_Error(
                'editorio_collector_not_found',
                __('Collector item not found.', 'editorio'),
                ['status' => 404]
            );
        }

        $deleted = $this->repository->delete($id);
        if (!$deleted) {
            return new WP_Error(
                'editorio_collector_delete_failed',
                __('Could not delete collector item.', 'editorio'),
                ['status' => 500]
            );
        }

        return true;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function normalize_payload(array $payload)
    {
        $source_id = (int) ($payload['source_id'] ?? 0);
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $description = wp_kses_post((string) ($payload['description'] ?? ''));
        $content_url = esc_url_raw((string) ($payload['content_url'] ?? ''));
        $image_url = esc_url_raw((string) ($payload['image_url'] ?? ''));
        $author = sanitize_text_field((string) ($payload['author'] ?? ''));
        $published_at = (string) ($payload['published_at'] ?? '');

        if ($source_id <= 0) {
            return new WP_Error(
                'editorio_collector_invalid_source_id',
                __('A valid source_id is required.', 'editorio'),
                ['status' => 400]
            );
        }

        if ($title === '') {
            return new WP_Error(
                'editorio_collector_invalid_title',
                __('Title is required.', 'editorio'),
                ['status' => 400]
            );
        }

        if ($content_url === '' || !wp_http_validate_url($content_url)) {
            return new WP_Error(
                'editorio_collector_invalid_url',
                __('A valid content URL is required.', 'editorio'),
                ['status' => 400]
            );
        }

        $hash = hash('sha256', $source_id . '|' . $content_url);

        return [
            'source_id' => $source_id,
            'title' => $title,
            'description' => $description,
            'content_url' => $content_url,
            'image_url' => $image_url,
            'author' => $author,
            'published_at' => $published_at,
            'hash' => $hash,
        ];
    }
}

