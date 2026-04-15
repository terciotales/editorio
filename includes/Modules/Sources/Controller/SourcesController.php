<?php

declare(strict_types=1);

namespace Editorio\Modules\Sources\Controller;

use Editorio\Modules\Sources\Service\SourcesService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SourcesController
{
    private SourcesService $service;

    public function __construct(SourcesService $service)
    {
        $this->service = $service;
    }

    public function register_routes(): void
    {
        register_rest_route(
            'editorio/v1',
            '/sources',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list'],
                    'permission_callback' => [$this, 'can_manage_sources'],
                    'args' => [
                        'is_active' => [
                            'required' => false,
                            'type' => 'boolean',
                        ],
                    ],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create'],
                    'permission_callback' => [$this, 'can_manage_sources'],
                    'args' => $this->get_write_args(),
                ],
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/sources/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get'],
                    'permission_callback' => [$this, 'can_manage_sources'],
                ],
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'update'],
                    'permission_callback' => [$this, 'can_manage_sources'],
                    'args' => $this->get_write_args(),
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete'],
                    'permission_callback' => [$this, 'can_manage_sources'],
                ],
            ]
        );
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $filters = [];
        if ($request->has_param('is_active')) {
            $filters['is_active'] = $request->get_param('is_active') ? 1 : 0;
        }

        return new WP_REST_Response($this->service->list($filters));
    }

    public function get(WP_REST_Request $request)
    {
        $source = $this->service->get((int) $request['id']);

        if ($source === null) {
            return new WP_Error('editorio_sources_not_found', __('Source not found.', 'editorio'), ['status' => 404]);
        }

        return new WP_REST_Response($source);
    }

    public function create(WP_REST_Request $request)
    {
        $result = $this->service->create($this->extract_write_payload($request));
        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response($result, 201);
    }

    public function update(WP_REST_Request $request)
    {
        $result = $this->service->update((int) $request['id'], $this->extract_write_payload($request));
        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response($result);
    }

    public function delete(WP_REST_Request $request)
    {
        $result = $this->service->delete((int) $request['id']);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response(['deleted' => true]);
    }

    public function can_manage_sources(): bool
    {
        return current_user_can('edit_posts');
    }

    private function extract_write_payload(WP_REST_Request $request): array
    {
        return [
            'name' => $request->get_param('name'),
            'feed_url' => $request->get_param('feed_url'),
            'is_active' => $request->get_param('is_active'),
        ];
    }

    private function get_write_args(): array
    {
        return [
            'name' => [
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'feed_url' => [
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
            ],
            'is_active' => [
                'required' => false,
                'type' => 'boolean',
            ],
        ];
    }
}
