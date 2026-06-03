<?php
declare(strict_types=1);
namespace Editorio\Modules\AI\Controller;
use Editorio\Modules\AI\Service\AIService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AIController
{
    private AIService $service;
    public function __construct(AIService $service)
    {
        $this->service = $service;
    }
    public function register_routes(): void
    {
        register_rest_route(
            'editorio/v1',
            '/ai/settings',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_settings'],
                    'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'save_settings'],
                    'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                ],
            ]
        );
        register_rest_route(
            'editorio/v1',
            '/ai/rewrite',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rewrite'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            ]
        );
    }
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->service->get_settings());
    }
    public function save_settings(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->service->save_settings($this->extract_settings($request)));
    }
    public function rewrite(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->rewrite((string) $request->get_param('content'));
        return new WP_REST_Response($result);
    }
    /**
     * @return array<string,mixed>
     */
    private function extract_settings(WP_REST_Request $request): array
    {
        return [
            'enabled' => (bool) $request->get_param('enabled'),
            'rewrite_prompt' => (string) $request->get_param('rewrite_prompt'),
            'curation_prompt' => (string) $request->get_param('curation_prompt'),
        ];
    }
}
