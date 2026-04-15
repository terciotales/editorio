<?php

declare(strict_types=1);

namespace Editorio\Modules\Sources\Controller;

use Editorio\Modules\Sources\Service\SourcesService;
use WP_REST_Request;
use WP_REST_Response;

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
            '/sources/status',
            [
                'methods' => 'GET',
                'callback' => [$this, 'status'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->service->get_status());
    }
}

