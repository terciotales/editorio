<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Service;

use Editorio\Modules\Collector\Adapter\JsonFeedAdapter;
use Editorio\Modules\Collector\Adapter\XmlFeedAdapter;
use Editorio\Modules\Collector\Contracts\FeedAdapterInterface;
use Editorio\Modules\Collector\Repository\CollectorRepository;
use Editorio\Modules\Collector\Repository\CollectorSyncRepository;
use Editorio\Modules\Sources\Repository\SourcesRepository;
use WP_Error;

final class CollectorService
{
    private const DEFAULT_BATCH_SIZE = 5;
    private const SYNC_LOCK_KEY = 'editorio_collector_batch_lock';

    private CollectorRepository $repository;

    private CollectorSyncRepository $sync_repository;

    private SourcesRepository $sources_repository;

    /**
     * @var FeedAdapterInterface[]
     */
    private array $adapters;

    /**
     * @param FeedAdapterInterface[] $adapters
     */
    public function __construct(
        CollectorRepository $repository,
        SourcesRepository $sources_repository,
        CollectorSyncRepository $sync_repository,
        array $adapters = []
    ) {
        $this->repository = $repository;
        $this->sources_repository = $sources_repository;
        $this->sync_repository = $sync_repository;
        $this->adapters = $adapters !== [] ? $adapters : [
            new JsonFeedAdapter(),
            new XmlFeedAdapter(),
        ];
    }

    public function install(): void
    {
        $this->repository->create_table();
        $this->sync_repository->create_table();
    }

    public function get_status(): array
    {
        $pending = $this->sync_repository->count_by_status('pending');
        $processing = $this->sync_repository->count_by_status('processing');
        $ok = $this->sync_repository->count_by_status('ok');
        $not_modified = $this->sync_repository->count_by_status('not_modified');
        $failed = $this->sync_repository->count_by_status('error');

        return [
            'module' => 'collector',
            'status' => 'ok',
            'items' => $this->repository->count(),
            'sources' => $this->sources_repository->count(),
            'sync_states' => $this->sync_repository->count(),
            'queue' => [
                'pending' => $pending,
                'processing' => $processing,
                'done' => $ok + $not_modified,
                'failed' => $failed,
            ],
            'counts' => [
                'collected' => $this->repository->count_by_status('collected'),
                'processing' => $this->repository->count_by_status('processing'),
                'processed' => $this->repository->count_by_status('processed'),
                'failed' => $this->repository->count_by_status('failed'),
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

        $created = $this->repository->upsert($normalized);
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
        $valid_statuses = ['collected', 'processing', 'processed', 'failed'];
        if (! in_array($status, $valid_statuses, true)) {
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
     * @return array<string,mixed>|WP_Error
     */
    public function collect_all()
    {
        $queued = $this->queue_all_sources();
        if ($queued instanceof WP_Error) {
            return $queued;
        }

        return $this->process_pending_batch(self::DEFAULT_BATCH_SIZE);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function collect_all_now()
    {
        $sources = $this->sources_repository->list(['is_active' => 1]);
        $results = [];
        $items = [];
        $items_collected = 0;

        foreach ($sources as $source) {
            $result = $this->collect_source((int) $source['id']);
            if ($result instanceof WP_Error) {
                return $result;
            }

            $results[] = $result;
            $items_collected += (int) ($result['items_collected'] ?? 0);
            if (! empty($result['items']) && is_array($result['items'])) {
                $items = array_merge($items, $result['items']);
            }
        }

        return [
            'status' => 'ok',
            'processed' => count($results),
            'items_collected' => $items_collected,
            'results' => $results,
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function collect_source(int $source_id)
    {
        $source = $this->sources_repository->get_by_id($source_id);
        if ($source === null) {
            return new WP_Error(
                'editorio_collector_source_not_found',
                __('Source not found.', 'editorio'),
                ['status' => 404]
            );
        }

        $sync_state = $this->sync_repository->get_by_source_id($source_id);
        $this->sync_repository->upsert([
            'source_id' => $source_id,
            'feed_url' => (string) $source['feed_url'],
            'feed_type' => (string) ($sync_state['feed_type'] ?? 'xml'),
            'etag' => (string) ($sync_state['etag'] ?? ''),
            'last_modified' => (string) ($sync_state['last_modified'] ?? ''),
            'last_synced_at' => (string) ($sync_state['last_synced_at'] ?? ''),
            'last_status' => 'processing',
            'last_error' => '',
            'items_collected_total' => (int) ($sync_state['items_collected_total'] ?? 0),
        ]);

        $response = wp_remote_get(
            (string) $source['feed_url'],
            [
                'timeout' => 20,
                'redirection' => 3,
                'headers' => $this->build_request_headers($sync_state),
                'user-agent' => 'Editorio/' . (defined('EDITORIO_VERSION') ? EDITORIO_VERSION : 'dev') . '; ' . home_url('/'),
            ]
        );

        if (is_wp_error($response)) {
            $this->sync_repository->upsert([
                'source_id' => $source_id,
                'feed_url' => (string) $source['feed_url'],
                'feed_type' => (string) ($sync_state['feed_type'] ?? 'xml'),
                'last_status' => 'error',
                'last_error' => $response->get_error_message(),
                'items_collected_total' => (int) ($sync_state['items_collected_total'] ?? 0),
            ]);

            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);

        if ($status_code === 304) {
            $this->sync_repository->upsert([
                'source_id' => $source_id,
                'feed_url' => (string) $source['feed_url'],
                'feed_type' => (string) ($sync_state['feed_type'] ?? 'xml'),
                'etag' => (string) ($sync_state['etag'] ?? ''),
                'last_modified' => (string) ($sync_state['last_modified'] ?? ''),
                'last_synced_at' => current_time('mysql'),
                'last_status' => 'not_modified',
                'last_error' => '',
                'items_collected_total' => (int) ($sync_state['items_collected_total'] ?? 0),
            ]);

            return [
                'source_id' => $source_id,
                'status' => 'not_modified',
                'items_collected' => 0,
            ];
        }

        if ($status_code < 200 || $status_code >= 300) {
            $message = sprintf(__('Unexpected feed response: %d', 'editorio'), $status_code);
            $this->sync_repository->upsert([
                'source_id' => $source_id,
                'feed_url' => (string) $source['feed_url'],
                'feed_type' => 'unknown',
                'last_status' => 'error',
                'last_error' => $message,
                'items_collected_total' => (int) ($sync_state['items_collected_total'] ?? 0),
            ]);

            return new WP_Error('editorio_collector_http_error', $message, ['status' => $status_code]);
        }

        $adapter = $this->resolve_adapter($content_type, $body);
        if ($adapter === null) {
            $message = __('Unsupported feed format.', 'editorio');
            $this->sync_repository->upsert([
                'source_id' => $source_id,
                'feed_url' => (string) $source['feed_url'],
                'feed_type' => 'unknown',
                'last_status' => 'error',
                'last_error' => $message,
                'items_collected_total' => (int) ($sync_state['items_collected_total'] ?? 0),
            ]);

            return new WP_Error('editorio_collector_unsupported_feed', $message, ['status' => 415]);
        }

        $items = $adapter->parse($body, $source, [
            'response_code' => $status_code,
            'content_type' => $content_type,
        ]);

        $source_limit = max(1, (int) ($source['news_limit'] ?? 10));
        if ($source_limit > 0) {
            $items = array_slice($items, 0, $source_limit);
        }

        $collected_items = 0;
        $collected_rows = [];
        foreach ($items as $item) {
            $normalized = $this->normalize_collected_item($item, $source, $adapter->get_format());
            if ($normalized instanceof WP_Error) {
                continue;
            }

            $stored = $this->repository->upsert($normalized);
            if ($stored !== null) {
                $collected_items++;
                $collected_rows[] = $stored;
            }
        }

        $etag = (string) wp_remote_retrieve_header($response, 'etag');
        $last_modified = (string) wp_remote_retrieve_header($response, 'last-modified');
        $previous_total = (int) ($sync_state['items_collected_total'] ?? 0);

        $this->sync_repository->upsert([
            'source_id' => $source_id,
            'feed_url' => (string) $source['feed_url'],
            'feed_type' => $adapter->get_format(),
            'etag' => $etag,
            'last_modified' => $last_modified,
            'last_synced_at' => current_time('mysql'),
            'last_status' => 'ok',
            'last_error' => '',
            'items_collected_total' => $previous_total + $collected_items,
        ]);

        return [
            'source_id' => $source_id,
            'status' => 'ok',
            'feed_type' => $adapter->get_format(),
            'items_collected' => $collected_items,
            'items' => $collected_rows,
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function queue_all_sources()
    {
        $sources = $this->sources_repository->list(['is_active' => 1]);
        $queued = 0;

        foreach ($sources as $source) {
            $result = $this->queue_source((int) $source['id']);
            if ($result instanceof WP_Error) {
                return $result;
            }

            $queued++;
        }

        if ($queued > 0) {
            $this->schedule_next_batch();
        }

        return [
            'sources_queued' => $queued,
            'status' => 'queued',
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function queue_source(int $source_id)
    {
        $source = $this->sources_repository->get_by_id($source_id);
        if ($source === null) {
            return new WP_Error(
                'editorio_collector_source_not_found',
                __('Source not found.', 'editorio'),
                ['status' => 404]
            );
        }

        $sync_state = $this->sync_repository->get_by_source_id($source_id);
        $queued = $this->sync_repository->upsert([
            'source_id' => $source_id,
            'feed_url' => (string) $source['feed_url'],
            'feed_type' => (string) ($sync_state['feed_type'] ?? 'xml'),
            'etag' => (string) ($sync_state['etag'] ?? ''),
            'last_modified' => (string) ($sync_state['last_modified'] ?? ''),
            'last_synced_at' => null,
            'last_status' => 'pending',
            'last_error' => '',
            'items_collected_total' => (int) ($sync_state['items_collected_total'] ?? 0),
        ]);

        if ($queued === null) {
            return new WP_Error(
                'editorio_collector_queue_failed',
                __('Could not queue source for collection.', 'editorio'),
                ['status' => 500]
            );
        }

        return $queued;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function process_pending_batch(int $limit = self::DEFAULT_BATCH_SIZE)
    {
        if ($this->acquire_lock()) {
            return new WP_Error(
                'editorio_collector_busy',
                __('Collector is already running.', 'editorio'),
                ['status' => 409]
            );
        }

        $pending = $this->sync_repository->list_by_status('pending', $limit);
        if ($pending === []) {
            $this->release_lock();

            return [
                'status' => 'idle',
                'processed' => 0,
                'items_collected' => 0,
                'remaining' => $this->sync_repository->count_by_status('pending'),
            ];
        }

        $processed = 0;
        $items_collected = 0;
        $results = [];

        foreach ($pending as $state) {
            $result = $this->collect_source((int) $state['source_id']);
            if ($result instanceof WP_Error) {
                $results[] = [
                    'source_id' => (int) $state['source_id'],
                    'status' => 'error',
                    'message' => $result->get_error_message(),
                ];
                continue;
            }

            $processed++;
            $items_collected += (int) ($result['items_collected'] ?? 0);
            $results[] = $result;
        }

        $remaining = $this->sync_repository->count_by_status('pending');
        $this->release_lock();

        if ($remaining > 0) {
            $this->schedule_next_batch();
        }

        return [
            'status' => 'running' . ($remaining > 0 ? '' : '-idle'),
            'processed' => $processed,
            'items_collected' => $items_collected,
            'remaining' => $remaining,
            'results' => $results,
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function normalize_payload(array $payload)
    {
        $source_id = (int) ($payload['source_id'] ?? 0);
        $source_type = sanitize_key((string) ($payload['source_type'] ?? 'xml'));
        $external_id = sanitize_text_field((string) ($payload['external_id'] ?? ''));
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $summary = wp_kses_post((string) ($payload['summary'] ?? ($payload['description'] ?? '')));
        $content_html = '';
        $content_url = esc_url_raw((string) ($payload['content_url'] ?? ''));
        $image_url = '';
        $author = '';
        $published_at = (string) ($payload['published_at'] ?? '');
        $raw_payload = '';

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

        if ($content_url === '' || ! wp_http_validate_url($content_url)) {
            return new WP_Error(
                'editorio_collector_invalid_url',
                __('A valid content URL is required.', 'editorio'),
                ['status' => 400]
            );
        }

        if ($external_id === '') {
            $external_id = $content_url;
        }

        $hash = hash('sha256', $source_id . '|' . $external_id . '|' . $content_url . '|' . $title);

        return [
            'source_id' => $source_id,
            'source_type' => $source_type !== '' ? $source_type : 'xml',
            'external_id' => $external_id,
            'title' => $title,
            'summary' => $summary,
            'content_html' => $content_html,
            'content_url' => $content_url,
            'image_url' => $image_url,
            'author' => $author,
            'published_at' => $published_at,
            'raw_payload' => $raw_payload,
            'hash' => $hash,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function build_request_headers(?array $sync_state): array
    {
        $headers = [
            'Accept' => 'application/feed+json, application/json;q=0.9, application/xml;q=0.8, text/xml;q=0.7, */*;q=0.6',
        ];

        if (is_array($sync_state)) {
            if (! empty($sync_state['etag'])) {
                $headers['If-None-Match'] = (string) $sync_state['etag'];
            }

            if (! empty($sync_state['last_modified'])) {
                $headers['If-Modified-Since'] = (string) $sync_state['last_modified'];
            }
        }

        return $headers;
    }

    private function schedule_next_batch(): void
    {
        if (! wp_next_scheduled('editorio_collector_sync')) {
            wp_schedule_single_event(time() + 60, 'editorio_collector_sync');
        }
    }

    private function acquire_lock(): bool
    {
        if (get_transient(self::SYNC_LOCK_KEY)) {
            return true;
        }

        set_transient(self::SYNC_LOCK_KEY, 1, 180);

        return false;
    }

    private function release_lock(): void
    {
        delete_transient(self::SYNC_LOCK_KEY);
    }

    private function resolve_adapter(string $content_type, string $body): ?FeedAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($content_type, $body)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function normalize_collected_item(array $item, array $source, string $source_type)
    {
        return $this->normalize_payload([
            'source_id' => $source['id'] ?? 0,
            'source_type' => $source_type,
            'external_id' => $item['external_id'] ?? '',
            'title' => $item['title'] ?? '',
            'summary' => $item['summary'] ?? '',
            'content_html' => $item['content_html'] ?? '',
            'content_url' => $item['content_url'] ?? '',
            'image_url' => $item['image_url'] ?? '',
            'author' => $item['author'] ?? '',
            'published_at' => $item['published_at'] ?? '',
            'raw_payload' => $item['raw_payload'] ?? [],
        ]);
    }
}
