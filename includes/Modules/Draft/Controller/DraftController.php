<?php

declare(strict_types=1);

namespace Editorio\Modules\Draft\Controller;

use Editorio\Modules\Draft\Service\DraftService;
use WP_REST_Request;
use WP_REST_Response;

final class DraftController
{
    private DraftService $service;

    public function __construct(DraftService $service)
    {
        $this->service = $service;
    }

    public function register_routes(): void
    {
        register_rest_route(
            'editorio/v1',
            '/draft/status',
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

