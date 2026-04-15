<?php

declare(strict_types=1);

namespace Editorio\Modules\Sources\Service;

use Editorio\Modules\Sources\Repository\SourcesRepository;
use WP_Error;

final class SourcesService
{
    private SourcesRepository $repository;

    public function __construct(SourcesRepository $repository)
    {
        $this->repository = $repository;
    }

    public function install(): void
    {
        $this->repository->create_table();
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

        if ($this->repository->exists_by_feed_url($normalized['feed_url'])) {
            return new WP_Error('editorio_sources_conflict', __('A source with this feed URL already exists.', 'editorio'), ['status' => 409]);
        }

        $created = $this->repository->create($normalized);
        if ($created === null) {
            return new WP_Error('editorio_sources_create_failed', __('Could not create source.', 'editorio'), ['status' => 500]);
        }

        return $created;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function update(int $id, array $payload)
    {
        $existing = $this->repository->get_by_id($id);
        if ($existing === null) {
            return new WP_Error('editorio_sources_not_found', __('Source not found.', 'editorio'), ['status' => 404]);
        }

        $normalized = $this->normalize_payload(array_merge($existing, $payload));
        if ($normalized instanceof WP_Error) {
            return $normalized;
        }

        if ($this->repository->exists_by_feed_url($normalized['feed_url'], $id)) {
            return new WP_Error('editorio_sources_conflict', __('A source with this feed URL already exists.', 'editorio'), ['status' => 409]);
        }

        $updated = $this->repository->update($id, $normalized);
        if ($updated === null) {
            return new WP_Error('editorio_sources_update_failed', __('Could not update source.', 'editorio'), ['status' => 500]);
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
            return new WP_Error('editorio_sources_not_found', __('Source not found.', 'editorio'), ['status' => 404]);
        }

        $deleted = $this->repository->delete($id);
        if (! $deleted) {
            return new WP_Error('editorio_sources_delete_failed', __('Could not delete source.', 'editorio'), ['status' => 500]);
        }

        return true;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function normalize_payload(array $payload)
    {
        $name = sanitize_text_field((string) ($payload['name'] ?? ''));
        $feed_url = esc_url_raw((string) ($payload['feed_url'] ?? ''));
        $is_active = isset($payload['is_active']) ? (bool) $payload['is_active'] : true;

        if ($name === '') {
            return new WP_Error('editorio_sources_invalid_name', __('Name is required.', 'editorio'), ['status' => 400]);
        }

        if ($feed_url === '' || ! wp_http_validate_url($feed_url)) {
            return new WP_Error('editorio_sources_invalid_url', __('A valid feed URL is required.', 'editorio'), ['status' => 400]);
        }

        return [
            'name' => $name,
            'feed_url' => $feed_url,
            'is_active' => $is_active ? 1 : 0,
        ];
    }
}
