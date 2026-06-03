<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Controller;

use Editorio\Modules\Collector\Service\CollectorService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CollectorController
{
    private CollectorService $service;

    public function __construct(CollectorService $service)
    {
        $this->service = $service;
    }

    public function register_routes(): void
    {
        register_rest_route(
            'editorio/v1',
            '/collector/status',
            [
                'methods' => 'GET',
                'callback' => [$this, 'status'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/collector/items',
            [
                'methods' => 'GET',
                'callback' => [$this, 'list_items'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/collector/items',
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_item'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/collector/sync',
            [
                'methods' => 'POST',
                'callback' => [$this, 'sync'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/collector/items/(?P<id>\d+)',
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_item'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/collector/items/(?P<id>\d+)/status',
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_item_status'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/collector/items/(?P<id>\d+)',
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_item'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->service->get_status());
    }

    public function list_items(WP_REST_Request $request): WP_REST_Response
    {
        $filters = [];

        $source_id = $request->get_param('source_id');
        if ($source_id !== null) {
            $filters['source_id'] = (int) $source_id;
        }

        $status = $request->get_param('status');
        if ($status !== null) {
            $filters['status'] = sanitize_text_field((string) $status);
        }

        $search = $request->get_param('search');
        if ($search !== null) {
            $filters['search'] = sanitize_text_field((string) $search);
        }

        $items = $this->service->list($filters);

        return new WP_REST_Response(['items' => $items, 'count' => count($items)]);
    }

    public function create_item(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $result = $this->service->create($payload);

        if ($result instanceof WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                $result->get_error_data()['status'] ?? 400
            );
        }

        return new WP_REST_Response($result, 201);
    }

    public function get_item(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $item = $this->service->get($id);

        if ($item === null) {
            return new WP_REST_Response(
                ['error' => __('Item not found.', 'editorio')],
                404
            );
        }

        return new WP_REST_Response($item);
    }

    public function update_item_status(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $payload = $request->get_json_params();
        $status = sanitize_text_field((string) ($payload['status'] ?? ''));

        $result = $this->service->update_status($id, $status);

        if ($result instanceof WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                $result->get_error_data()['status'] ?? 400
            );
        }

        return new WP_REST_Response($result);
    }

    public function delete_item(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $result = $this->service->delete($id);

        if ($result instanceof WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                $result->get_error_data()['status'] ?? 400
            );
        }

        return new WP_REST_Response(['success' => true]);
    }

    public function sync(WP_REST_Request $request): WP_REST_Response
    {
        $source_id = (int) $request->get_param('source_id');
        $batch_size = (int) $request->get_param('batch_size');
        if ($batch_size <= 0) {
            $batch_size = 5;
        }

        if ($source_id > 0) {
            $result = $this->service->collect_source($source_id);
        } else {
            $queued = $this->service->queue_all_sources();
            if ($queued instanceof WP_Error) {
                return new WP_REST_Response(
                    ['error' => $queued->get_error_message()],
                    (int) ($queued->get_error_data()['status'] ?? 400)
                );
            }

            $result = $this->service->process_pending_batch($batch_size);
        }

        if ($result instanceof WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                (int) ($result->get_error_data()['status'] ?? 400)
            );
        }

        return new WP_REST_Response($result);
    }
}
