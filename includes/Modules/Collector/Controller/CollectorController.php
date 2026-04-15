<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Controller;

use Editorio\Modules\Collector\Service\CollectorService;
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
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->service->get_status());
    }
}

