<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher\Controller;

use Editorio\Modules\Publisher\Service\PublisherService;
use WP_REST_Request;
use WP_REST_Response;

final class PublisherController
{
    private PublisherService $service;

    public function __construct(PublisherService $service)
    {
        $this->service = $service;
    }

    public function register_routes(): void
    {
        register_rest_route(
            'editorio/v1',
            '/publisher/debug/collector-items',
            [
                'methods' => 'GET',
                'callback' => [$this, 'debug_collector_items'],
                'permission_callback' => fn() => current_user_can('manage_options'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/install-tables',
            [
                'methods' => 'POST',
                'callback' => [$this, 'install_tables'],
                'permission_callback' => fn() => current_user_can('manage_options'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/process-batch',
            [
                'methods' => 'POST',
                'callback' => [$this, 'process_batch'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/start',
            [
                'methods' => 'POST',
                'callback' => [$this, 'start_workflow'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflows',
            [
                'methods' => 'GET',
                'callback' => [$this, 'list_workflows'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflow/(?P<session_id>[^/]+)/status',
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_workflow_status'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflow/(?P<session_id>[^/]+)/resume',
            [
                'methods' => 'GET',
                'callback' => [$this, 'resume_workflow'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflow/(?P<session_id>[^/]+)/finalize-collection',
            [
                'methods' => 'POST',
                'callback' => [$this, 'finalize_collection'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflow/(?P<session_id>[^/]+)/retry-curation',
            [
                'methods' => 'POST',
                'callback' => [$this, 'retry_curation'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflow/(?P<session_id>[^/]+)/select-items',
            [
                'methods' => 'POST',
                'callback' => [$this, 'select_items'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflow/(?P<session_id>[^/]+)/approve-item',
            [
                'methods' => 'POST',
                'callback' => [$this, 'approve_item'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflow/(?P<session_id>[^/]+)/finalize-review',
            [
                'methods' => 'POST',
                'callback' => [$this, 'finalize_review'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/workflow/(?P<session_id>[^/]+)/save-drafts',
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_drafts'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/url-rewrite-draft',
            [
                'methods' => 'POST',
                'callback' => [$this, 'generate_url_rewrite_draft'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );

        register_rest_route(
            'editorio/v1',
            '/publisher/url-generated-post',
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_url_generated_post'],
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]
        );
    }

    public function debug_collector_items(): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'editorio_collector_items';
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $items = $wpdb->get_results("SELECT id, title, source_id, status FROM {$table} LIMIT 5");
        
        return new WP_REST_Response([
            'total_count' => $count,
            'sample_items' => $items,
            'table_name' => $table,
        ]);
    }

    public function install_tables(): WP_REST_Response
    {
        $repository = new \Editorio\Modules\Publisher\Repository\PublisherRepository();
        $repository->install();
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Tables installed successfully',
        ]);
    }

    public function start_workflow(): WP_REST_Response
    {
        $user_id = (int) get_current_user_id();
        $result = $this->service->start_workflow($user_id);

        return new WP_REST_Response($result);
    }

    public function list_workflows(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) ($request->get_param('limit') ?? 8);

        return new WP_REST_Response($this->service->list_recent_workflows($limit));
    }

    public function process_batch(): WP_REST_Response
    {
        $collector_repo = new \Editorio\Modules\Collector\Repository\CollectorRepository();
        $sources_repo = new \Editorio\Modules\Sources\Repository\SourcesRepository();
        $sync_repo = new \Editorio\Modules\Collector\Repository\CollectorSyncRepository();
        
        $collector = new \Editorio\Modules\Collector\Service\CollectorService(
            $collector_repo,
            $sources_repo,
            $sync_repo
        );
        $result = $collector->process_pending_batch();

        return new WP_REST_Response([
            'success' => true,
            'batch_result' => $result,
        ]);
    }

    public function get_workflow_status(WP_REST_Request $request): WP_REST_Response
    {
        $session_id = $request->get_param('session_id');
        $status = $this->service->get_workflow_status($session_id);

        return new WP_REST_Response($status);
    }

    public function resume_workflow(WP_REST_Request $request): WP_REST_Response
    {
        $session_id = $request->get_param('session_id');
        $result = $this->service->resume_workflow($session_id);

        if ($result instanceof \WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                (int) ($result->get_error_data()['status'] ?? 404)
            );
        }

        return new WP_REST_Response($result);
    }

    public function finalize_collection(WP_REST_Request $request): WP_REST_Response
    {
        $session_id = $request->get_param('session_id');
        $result = $this->service->finalize_collection($session_id);

        return new WP_REST_Response($result);
    }

    public function retry_curation(WP_REST_Request $request): WP_REST_Response
    {
        $session_id = $request->get_param('session_id');
        $result = $this->service->retry_curation($session_id);

        if ($result instanceof \WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                (int) ($result->get_error_data()['status'] ?? 400)
            );
        }

        return new WP_REST_Response($result);
    }

    public function select_items(WP_REST_Request $request): WP_REST_Response
    {
        $session_id = $request->get_param('session_id');
        $item_ids = $request->get_json_params()['item_ids'] ?? [];

        $result = $this->service->select_items($session_id, $item_ids);

        return new WP_REST_Response($result);
    }

    public function approve_item(WP_REST_Request $request): WP_REST_Response
    {
        $session_id = $request->get_param('session_id');
        $params = $request->get_json_params();
        $item_id = $params['item_id'] ?? null;
        $approved = $params['approved'] ?? false;
        $generated_title = (string) ($params['generated_title'] ?? '');
        $generated_content = (string) ($params['generated_content'] ?? '');
        $generated_summary = (string) ($params['generated_summary'] ?? '');
        $generated_categories = is_array($params['generated_categories'] ?? null) ? $params['generated_categories'] : [];
        $generated_tags = is_array($params['generated_tags'] ?? null) ? $params['generated_tags'] : [];
        $featured_image_id = (int) ($params['featured_image_id'] ?? 0);
        $featured_image_url = (string) ($params['featured_image_url'] ?? '');

        $result = $this->service->approve_item(
            $session_id,
            (int) $item_id,
            (bool) $approved,
            $generated_title,
            $generated_content,
            $generated_summary,
            $generated_categories,
            $generated_tags,
            $featured_image_id,
            $featured_image_url
        );

        if ($result instanceof \WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                (int) ($result->get_error_data()['status'] ?? 400)
            );
        }

        return new WP_REST_Response($result);
    }

    public function finalize_review(WP_REST_Request $request): WP_REST_Response
    {
        $session_id = $request->get_param('session_id');
        $result = $this->service->finalize_review($session_id);

        if ($result instanceof \WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                (int) ($result->get_error_data()['status'] ?? 400)
            );
        }

        return new WP_REST_Response($result);
    }

    public function save_drafts(WP_REST_Request $request): WP_REST_Response
    {
        $session_id = $request->get_param('session_id');
        $params = $request->get_json_params();
        $items = is_array($params['items'] ?? null) ? $params['items'] : [];
        $result = $this->service->save_approved_drafts($session_id, $items);

        return new WP_REST_Response($result);
    }

    public function generate_url_rewrite_draft(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params();
        $result = $this->service->generate_url_rewrite_draft(is_array($params) ? $params : []);

        return new WP_REST_Response($result);
    }

    public function create_url_generated_post(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params();
        $result = $this->service->create_url_generated_post(is_array($params) ? $params : []);

        if ($result instanceof \WP_Error) {
            return new WP_REST_Response(
                ['error' => $result->get_error_message()],
                (int) ($result->get_error_data()['status'] ?? 400)
            );
        }

        return new WP_REST_Response($result);
    }
}
