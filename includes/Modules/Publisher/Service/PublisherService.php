<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher\Service;

use Editorio\Modules\AI\Service\AIService;
use Editorio\Modules\Collector\Repository\CollectorRepository;
use Editorio\Modules\Collector\Service\CollectorService;
use Editorio\Modules\Publisher\Repository\PublisherRepository;
use Editorio\Modules\Sources\Repository\SourcesRepository;
use WP_Error;

final class PublisherService
{
    private PublisherRepository $repository;
    private CollectorService $collector_service;
    private CollectorRepository $collector_repository;
    private SourcesRepository $sources_repository;
    private AIService $ai_service;

    public function __construct(
        PublisherRepository $repository,
        CollectorService $collector_service,
        CollectorRepository $collector_repository,
        SourcesRepository $sources_repository,
        AIService $ai_service
    ) {
        $this->repository = $repository;
        $this->collector_service = $collector_service;
        $this->collector_repository = $collector_repository;
        $this->sources_repository = $sources_repository;
        $this->ai_service = $ai_service;
    }

    /**
     * Inicia um novo workflow de publicação
     */
    public function start_workflow(int $user_id): array
    {
        $session_id = $this->repository->create_session((string) $user_id);

        $collection_result = $this->collector_service->collect_all_now();
        if ($collection_result instanceof WP_Error) {
            return [
                'session_id' => $session_id,
                'stage' => 'collecting',
                'collection_error' => $collection_result->get_error_message(),
            ];
        }

        $collected_items = is_array($collection_result['items'] ?? null) ? $collection_result['items'] : [];
        if ($collected_items !== []) {
            $this->repository->add_items($session_id, $collected_items);
        }

        $this->repository->update_session(
            $session_id,
            [
                'collected_count' => count($collected_items),
            ]
        );

        return [
            'session_id' => $session_id,
            'stage' => 'collecting',
            'collection_result' => $collection_result,
        ];
    }

    /**
     * Retorna o status atual do workflow
     */
    public function get_workflow_status(string $session_id): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $collector_status = $this->collector_service->get_status();
        $session_collected_count = (int) ($session['collected_count'] ?? 0);

        if (is_array($collector_status)) {
            $collector_status['global_items'] = (int) ($collector_status['items'] ?? 0);
            $collector_status['items'] = $session_collected_count;
            $collector_status['session_items'] = $session_collected_count;

            if (isset($collector_status['counts']) && is_array($collector_status['counts'])) {
                $collector_status['counts']['collected'] = $session_collected_count;
            }
        }

        return [
            'session_id' => $session_id,
            'stage' => $session['stage'],
            'collected_count' => $session['collected_count'],
            'curated_count' => $session['curated_count'],
            'selected_count' => $session['selected_count'],
            'approved_count' => $session['approved_count'],
            'rejected_count' => $session['rejected_count'],
            'collector_status' => $collector_status,
            'created_at' => $session['created_at'],
            'updated_at' => $session['updated_at'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function list_recent_workflows(int $limit = 8): array
    {
        $sessions = $this->repository->list_recent_sessions($limit);

        return [
            'items' => array_map(
                fn (array $session): array => [
                    'session_id' => (string) ($session['id'] ?? ''),
                    'stage' => $this->normalize_stage((string) ($session['stage'] ?? 'collecting')),
                    'collected_count' => (int) ($session['collected_count'] ?? 0),
                    'curated_count' => (int) ($session['curated_count'] ?? 0),
                    'selected_count' => (int) ($session['selected_count'] ?? 0),
                    'approved_count' => (int) ($session['approved_count'] ?? 0),
                    'rejected_count' => (int) ($session['rejected_count'] ?? 0),
                    'created_at' => (string) ($session['created_at'] ?? ''),
                    'updated_at' => (string) ($session['updated_at'] ?? ''),
                    'is_finished' => $this->normalize_stage((string) ($session['stage'] ?? 'collecting')) === 'completed',
                ],
                $sessions
            ),
        ];
    }

    /**
     * Retorna o estado completo necessário para retomar o workflow pela URL.
     */
    public function resume_workflow(string $session_id): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found', ['status' => 404]);
        }

        $stage = $this->normalize_stage((string) ($session['stage'] ?? 'collecting'));
        if ($stage !== (string) ($session['stage'] ?? '')) {
            $this->repository->update_stage($session_id, $stage);
            $session['stage'] = $stage;
        }

        $curated_items = $this->repository->get_curated_items($session_id);
        $selected_items = $this->repository->get_selected_items($session_id);
        $approval_summary = $this->get_approval_summary($session_id);

        if ($approval_summary instanceof WP_Error) {
            return $approval_summary;
        }

        $data = [
            'session' => $session,
            'items' => $curated_items,
            'total_items' => (int) ($session['collected_count'] ?? 0),
            'selected_item_ids' => array_values(array_map(
                static fn (array $item): int => (int) ($item['id'] ?? 0),
                $selected_items
            )),
            'selected_items' => $selected_items,
            'summary' => $this->format_approval_summary($approval_summary),
        ];

        if ($stage === 'collecting') {
            $status = $this->get_workflow_status($session_id);
            if ($status instanceof WP_Error) {
                return $status;
            }

            $data['status'] = $status;
            $data['collector_status'] = $status['collector_status'] ?? null;
        }

        if ($stage === 'completed') {
            $created_posts = $this->get_created_posts_for_session($session_id);
            $data['created_posts'] = $created_posts;
            $data['summary'] = $this->build_completed_summary($session_id, $data['summary'], $created_posts);
        }

        return [
            'session_id' => $session_id,
            'stage' => $stage,
            'data' => $data,
        ];
    }

    /**
     * Finaliza a coleta e passa os items para a sessão de workflow
     */
    public function finalize_collection(string $session_id): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $sources = $this->sources_repository->list();
        $source_map = [];
        foreach ($sources as $source) {
            $source_map[(int) $source['id']] = $source;
        }

        $workflow_items = $this->repository->get_session_items($session_id);

        if ($workflow_items === []) {
            $collected_items = $this->collector_repository->list([
                'collected_after' => (string) $session['created_at'],
            ]);
            $collected_items = array_map(
                static function (array $item) use ($source_map): array {
                    $source = $source_map[(int) $item['source_id']] ?? null;

                    return $item + [
                        'source_name' => is_array($source) ? (string) ($source['name'] ?? '') : '',
                        'source_feed_url' => is_array($source) ? (string) ($source['feed_url'] ?? '') : '',
                    ];
                },
                $collected_items
            );

            if (empty($collected_items)) {
                return new WP_Error('no_items', 'No items collected');
            }

            $this->repository->add_items($session_id, $collected_items);
            $workflow_items = $this->repository->get_session_items($session_id);
        }
        $curated_stories = $this->ai_service->curate_items($workflow_items, 10);
        $curation_metadata = $this->extract_curation_metadata($curated_stories);

        if ($curated_stories !== []) {
            $this->repository->replace_curated_stories($session_id, $curated_stories);
            $curated_items = $this->decorate_curated_items(
                $this->repository->get_curated_items($session_id),
                $curation_metadata['curation_mode'],
                $curation_metadata['curation_error']
            );
        } else {
            $curated_items = [];
        }

        // Atualizar contagem
        $this->repository->update_session(
            $session_id,
            [
                'collected_count' => count($workflow_items),
                'curated_count' => count($curated_items),
                'stage' => 'curating',
            ]
        );

        return [
            'session_id' => $session_id,
            'total_items' => count($workflow_items),
            'stage' => 'curating',
            'items' => $curated_items,
        ];
    }

    /**
     * Retorna os items sintetizados para a sessão; se ainda não existirem, gera a curadoria e persiste.
     */
    public function get_curated_items(string $session_id): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $items = $this->repository->get_curated_items($session_id);

        // Se não há items curados, gera pautas sintetizadas e persiste no workflow
        if (empty($items)) {
            $all_items = $this->repository->get_session_items($session_id);
            $curated_stories = $this->ai_service->curate_items($all_items, 10);
            $curation_metadata = $this->extract_curation_metadata($curated_stories);

            if ($curated_stories !== []) {
                $this->repository->replace_curated_stories($session_id, $curated_stories);
                $items = $this->decorate_curated_items(
                    $this->repository->get_curated_items($session_id),
                    $curation_metadata['curation_mode'],
                    $curation_metadata['curation_error']
                );
            }
        }

        return $items;
    }

    /**
     * Tenta refazer a curadoria com IA para a sessão atual
     */
    public function retry_curation(string $session_id): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (! $session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $items = $this->repository->get_session_items($session_id);
        if ($items === []) {
            return new WP_Error('no_items', 'No items available for curation');
        }

        $curated_stories = $this->ai_service->curate_items($items, 10);
        if ($curated_stories === []) {
            return new WP_Error('curation_failed', 'No curated items returned');
        }

        $curation_metadata = $this->extract_curation_metadata($curated_stories);
        $this->repository->replace_curated_stories($session_id, $curated_stories);
        $curated_items = $this->decorate_curated_items(
            $this->repository->get_curated_items($session_id),
            $curation_metadata['curation_mode'],
            $curation_metadata['curation_error']
        );
        $this->repository->update_session(
            $session_id,
            [
                'curated_count' => count($curated_items),
                'stage' => 'curating',
            ]
        );

        return [
            'session_id' => $session_id,
            'stage' => 'curating',
            'total_items' => count($items),
            'items' => $curated_items,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $stories
     * @return array{curation_mode:string,curation_error:string}
     */
    private function extract_curation_metadata(array $stories): array
    {
        $first_story = $stories[0] ?? [];

        return [
            'curation_mode' => (string) ($first_story['curation_mode'] ?? 'ai'),
            'curation_error' => (string) ($first_story['curation_error'] ?? ''),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function decorate_curated_items(array $items, string $curation_mode, string $curation_error): array
    {
        return array_map(
            static function (array $item) use ($curation_mode, $curation_error): array {
                $item['curation_mode'] = $curation_mode;
                $item['curation_error'] = $curation_error;

                return $item;
            },
            $items
        );
    }

    /**
     * Usuário seleciona quais items quer revisar
     */
    public function select_items(string $session_id, array $item_ids): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $this->repository->mark_selected($session_id, $item_ids);

        $this->repository->update_stage($session_id, 'reviewing');

        $selected_items = $this->repository->get_selected_items($session_id);

        return [
            'session_id' => $session_id,
            'selected_count' => count($selected_items),
            'selected_item_ids' => array_values(array_map(
                static fn (array $item): int => (int) ($item['id'] ?? 0),
                $selected_items
            )),
            'selected_items' => $selected_items,
            'stage' => 'reviewing',
        ];
    }

    /**
     * Aprova ou desaprova um item durante a revisão
     */
    public function approve_item(
        string $session_id,
        int $item_id,
        bool $approved,
        string $generated_title = '',
        string $generated_content = '',
        string $generated_summary = '',
        array $generated_categories = [],
        array $generated_tags = [],
        int $featured_image_id = 0,
        string $featured_image_url = ''
    ): array|WP_Error {
        $status = $approved ? 'approved' : 'rejected';

        $session = $this->repository->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $this->repository->update_item_approval(
            $session_id,
            $item_id,
            $status,
            $generated_title,
            $generated_content,
            $generated_summary,
            $generated_categories,
            $generated_tags,
            $featured_image_id,
            $featured_image_url
        );

        // Retornar próximo item a revisar
        $selected_items = $this->repository->get_selected_items($session_id);
        $next_item = null;
        $current_index = 0;

        foreach ($selected_items as $idx => $item) {
            if ($item['id'] == $item_id) {
                $current_index = $idx;
                if (isset($selected_items[$idx + 1])) {
                    $next_item = $selected_items[$idx + 1];
                }
                break;
            }
        }

        $current_session = $this->repository->get_session($session_id) ?: [];

        $pending_items = array_filter(
            $selected_items,
            static fn (array $item): bool => !in_array((string) ($item['approval_status'] ?? ''), ['approved', 'rejected'], true)
        );
        $summary = null;

        if ($selected_items !== [] && $pending_items === []) {
            $this->repository->update_stage($session_id, 'confirming');
            $approval_summary = $this->get_approval_summary($session_id);
            $summary = $approval_summary instanceof WP_Error ? null : $this->format_approval_summary($approval_summary);
        }

        return [
            'session_id' => $session_id,
            'item_id' => $item_id,
            'status' => $status,
            'current_index' => $current_index,
            'total_items' => count($selected_items),
            'next_item' => $next_item,
            'approved_count' => (int) ($current_session['approved_count'] ?? 0),
            'rejected_count' => (int) ($current_session['rejected_count'] ?? 0),
            'stage' => $summary !== null ? 'confirming' : 'reviewing',
            'summary' => $summary,
        ];
    }

    public function finalize_review(string $session_id): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (! $session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $selected_items = $this->repository->get_selected_items($session_id);
        if ($selected_items === []) {
            return new WP_Error('no_selected_items', 'No selected items to finalize');
        }

        $pending_items = array_filter(
            $selected_items,
            static fn (array $item): bool => ! in_array((string) ($item['approval_status'] ?? ''), ['approved', 'rejected'], true)
        );

        $auto_rejected_count = 0;
        if ($pending_items !== []) {
            $auto_rejected_count = $this->repository->reject_pending_selected_items($session_id);
        }

        $this->repository->update_stage($session_id, 'confirming');
        $approval_summary = $this->get_approval_summary($session_id);
        if ($approval_summary instanceof WP_Error) {
            return $approval_summary;
        }

        return [
            'session_id' => $session_id,
            'stage' => 'confirming',
            'summary' => $this->format_approval_summary($approval_summary),
            'auto_rejected_count' => $auto_rejected_count,
        ];
    }

    /**
     * Retorna resumo final de aprovações e rejeições
     */
    public function get_approval_summary(string $session_id): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $items = $this->repository->get_approval_summary($session_id);

        $approved = array_filter($items, fn($item) => $item['approval_status'] === 'approved');
        $rejected = array_filter($items, fn($item) => $item['approval_status'] === 'rejected');

        return [
            'session_id' => $session_id,
            'approved' => array_values($approved),
            'rejected' => array_values($rejected),
            'approved_count' => count($approved),
            'rejected_count' => count($rejected),
            'stage' => 'confirming',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function generate_url_rewrite_draft(array $payload): array
    {
        return $this->ai_service->generate_url_rewrite_draft($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function create_url_generated_post(array $payload): array|WP_Error
    {
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $content = (string) ($payload['content'] ?? '');
        $summary = sanitize_textarea_field((string) ($payload['summary'] ?? ''));
        $category_names = $this->decode_string_list($payload['categories'] ?? []);
        $tags = $this->decode_string_list($payload['tags'] ?? []);
        $featured_image_id = (int) ($payload['featured_image_id'] ?? 0);
        $action = sanitize_key((string) ($payload['action'] ?? 'draft'));
        $scheduled_at = sanitize_text_field((string) ($payload['scheduled_at'] ?? ''));

        if ($title === '') {
            return new WP_Error('missing_title', __('A title is required.', 'editorio'), ['status' => 400]);
        }

        if (trim(wp_strip_all_tags($content)) === '') {
            return new WP_Error('missing_content', __('Content is required.', 'editorio'), ['status' => 400]);
        }

        if (! in_array($action, ['draft', 'publish', 'schedule'], true)) {
            $action = 'draft';
        }

        $post_status = match ($action) {
            'publish' => 'publish',
            'schedule' => 'future',
            default => 'draft',
        };

        if ($post_status === 'future') {
            $timestamp = strtotime($scheduled_at);
            if ($scheduled_at === '' || $timestamp === false) {
                return new WP_Error('invalid_schedule', __('A valid schedule date is required.', 'editorio'), ['status' => 400]);
            }
        }

        $post_data = [
            'post_type' => 'post',
            'post_status' => $post_status,
            'post_title' => $title,
            'post_content' => wp_kses_post($content),
            'post_excerpt' => $summary,
            'post_author' => get_current_user_id(),
            'post_category' => $this->resolve_category_ids($category_names),
            'tags_input' => $tags,
        ];

        if ($post_status === 'future') {
            $post_data['post_date'] = date('Y-m-d H:i:s', strtotime($scheduled_at));
            $post_data['post_date_gmt'] = get_gmt_from_date($post_data['post_date']);
        }

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if ($featured_image_id > 0 && get_post_type($featured_image_id) === 'attachment') {
            set_post_thumbnail((int) $post_id, $featured_image_id);
        }

        return [
            'post_id' => (int) $post_id,
            'title' => $title,
            'action' => $action,
            'post_status' => $post_status,
            'view_url' => $this->get_post_view_url((int) $post_id),
            'edit_url' => get_edit_post_link((int) $post_id, 'raw') ?: '',
        ];
    }

    /**
     * Salva items aprovados como drafts do WordPress
     */
    public function save_approved_drafts(string $session_id, array $item_actions = []): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $items = $this->repository->get_approval_summary($session_id);

        $approved_items = array_filter(
            $items,
            static fn (array $item): bool => (string) ($item['approval_status'] ?? '') === 'approved'
        );
        $action_map = $this->normalize_final_item_actions($item_actions);

        $created_posts = [];
        $failed_posts = [];
        $action_counts = [
            'published' => 0,
            'drafted' => 0,
            'scheduled' => 0,
            'excluded' => 0,
        ];
        $excluded_count = 0;

        foreach ($approved_items as $item) {
            $item_id = (int) ($item['id'] ?? 0);
            $item_action = $action_map[$item_id] ?? ['action' => 'draft', 'scheduled_at' => ''];
            $action = (string) ($item_action['action'] ?? 'draft');

            if ($action === 'exclude') {
                $this->repository->update_item_finalization($session_id, $item_id, 'exclude');
                $excluded_count++;
                $action_counts['excluded']++;
                continue;
            }

            $post_status = match ($action) {
                'publish' => 'publish',
                'schedule' => 'future',
                default => 'draft',
            };
            $scheduled_at = (string) ($item_action['scheduled_at'] ?? '');
            if ($post_status === 'future') {
                $timestamp = strtotime($scheduled_at);
                if ($scheduled_at === '' || $timestamp === false) {
                    $this->repository->update_item_finalization($session_id, $item_id, $action);
                    $failed_posts[] = [
                        'workflow_item_id' => $item_id,
                        'title' => $item['generated_title'] ?? 'Untitled',
                        'action' => $action,
                        'error' => __('A valid schedule date is required.', 'editorio'),
                    ];
                    continue;
                }
            }

            $category_ids = $this->resolve_category_ids($this->decode_string_list($item['generated_categories'] ?? ''));
            $tags = $this->decode_string_list($item['generated_tags'] ?? '');

            $post_data = [
                'post_type' => 'post',
                'post_status' => $post_status,
                'post_title' => $item['generated_title'] ?? 'Untitled',
                'post_content' => $item['generated_content'] ?? '',
                'post_excerpt' => (string) ($item['generated_summary'] ?? ''),
                'post_author' => $session['user_id'],
                'post_category' => $category_ids,
                'tags_input' => $tags,
                'meta_input' => [
                    '_editorio_workflow_item_id' => $item['id'],
                    '_editorio_session_id' => $session_id,
                ],
            ];

            if ($post_status === 'future') {
                $post_data['post_date'] = date('Y-m-d H:i:s', strtotime($scheduled_at));
                $post_data['post_date_gmt'] = get_gmt_from_date($post_data['post_date']);
            }

            $post_id = wp_insert_post($post_data);

            if (is_wp_error($post_id)) {
                $this->repository->update_item_finalization($session_id, $item_id, $action);
                $failed_posts[] = [
                    'workflow_item_id' => $item_id,
                    'title' => $item['generated_title'] ?? 'Untitled',
                    'action' => $action,
                    'error' => $post_id->get_error_message(),
                ];
            } else {
                $featured_image_id = (int) ($item['featured_image_id'] ?? 0);
                if ($featured_image_id > 0 && get_post_type($featured_image_id) === 'attachment') {
                    set_post_thumbnail((int) $post_id, $featured_image_id);
                }

                if ($action === 'publish') {
                    $action_counts['published']++;
                } elseif ($action === 'schedule') {
                    $action_counts['scheduled']++;
                } else {
                    $action_counts['drafted']++;
                }

                $this->repository->update_item_finalization(
                    $session_id,
                    $item_id,
                    $action,
                    (int) $post_id,
                    $post_status
                );

                $created_posts[] = [
                    'workflow_item_id' => $item['id'],
                    'post_id' => $post_id,
                    'title' => $item['generated_title'] ?? 'Untitled',
                    'action' => $action,
                    'post_status' => $post_status,
                    'view_url' => $this->get_post_view_url((int) $post_id),
                    'edit_url' => get_edit_post_link((int) $post_id, 'raw') ?: '',
                ];
            }
        }

        $this->repository->update_stage($session_id, 'completed');

        return [
            'session_id' => $session_id,
            'created_posts' => $created_posts,
            'failed_posts' => $failed_posts,
            'total_created' => count($created_posts),
            'summary' => [
                'total' => count($items),
                'approved' => count($approved_items),
                'rejected' => count(array_filter(
                    $items,
                    static fn (array $item): bool => (string) ($item['approval_status'] ?? '') === 'rejected'
                )),
                'created' => count($created_posts),
                'excluded' => $excluded_count,
                'failed' => count($failed_posts),
                'published' => $action_counts['published'],
                'drafted' => $action_counts['drafted'],
                'scheduled' => $action_counts['scheduled'],
            ],
            'stage' => 'completed',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $item_actions
     * @return array<int,array{action:string,scheduled_at:string}>
     */
    private function normalize_final_item_actions(array $item_actions): array
    {
        $allowed_actions = ['publish', 'draft', 'schedule', 'exclude'];
        $normalized = [];

        foreach ($item_actions as $item_action) {
            if (! is_array($item_action)) {
                continue;
            }

            $item_id = (int) ($item_action['item_id'] ?? 0);
            if ($item_id <= 0) {
                continue;
            }

            $action = sanitize_key((string) ($item_action['action'] ?? 'draft'));
            if (! in_array($action, $allowed_actions, true)) {
                $action = 'draft';
            }

            $normalized[$item_id] = [
                'action' => $action,
                'scheduled_at' => sanitize_text_field((string) ($item_action['scheduled_at'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function normalize_stage(string $stage): string
    {
        return match ($stage) {
            'confirmation' => 'confirming',
            'collecting',
            'curating',
            'reviewing',
            'confirming',
            'completed' => $stage,
            default => 'collecting',
        };
    }

    /**
     * @param array{approved?:array<int,array<string,mixed>>,rejected?:array<int,array<string,mixed>>,approved_count?:int,rejected_count?:int} $approval_summary
     * @return array<string,mixed>
     */
    private function format_approval_summary(array $approval_summary): array
    {
        $approved = is_array($approval_summary['approved'] ?? null) ? $approval_summary['approved'] : [];
        $rejected = is_array($approval_summary['rejected'] ?? null) ? $approval_summary['rejected'] : [];

        return [
            'approved' => (int) ($approval_summary['approved_count'] ?? count($approved)),
            'rejected' => (int) ($approval_summary['rejected_count'] ?? count($rejected)),
            'total' => count($approved) + count($rejected),
            'approved_items' => $approved,
            'rejected_items' => $rejected,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_created_posts_for_session(string $session_id): array
    {
        $post_ids = get_posts([
            'post_type' => 'post',
            'post_status' => ['draft', 'publish', 'pending', 'future', 'private'],
            'meta_key' => '_editorio_session_id',
            'meta_value' => $session_id,
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        return array_map(
            fn ($post_id): array => [
                'post_id' => (int) $post_id,
                'title' => get_the_title((int) $post_id),
                'action' => $this->map_post_status_to_action((string) get_post_status((int) $post_id)),
                'post_status' => (string) get_post_status((int) $post_id),
                'view_url' => $this->get_post_view_url((int) $post_id),
                'edit_url' => get_edit_post_link((int) $post_id, 'raw') ?: '',
            ],
            is_array($post_ids) ? $post_ids : []
        );
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<int,array<string,mixed>> $created_posts
     * @return array<string,mixed>
     */
    private function build_completed_summary(string $session_id, array $summary, array $created_posts): array
    {
        $selected_items = $this->repository->get_selected_items($session_id);
        $approved_items = array_filter(
            $selected_items,
            static fn (array $item): bool => (string) ($item['approval_status'] ?? '') === 'approved'
        );

        $has_persisted_finalization = array_reduce(
            $approved_items,
            static fn (bool $carry, array $item): bool => $carry || (string) ($item['final_action'] ?? '') !== '',
            false
        );

        if ($has_persisted_finalization) {
            $published = 0;
            $drafted = 0;
            $scheduled = 0;
            $excluded = 0;
            $failed = 0;
            $created = 0;

            foreach ($approved_items as $item) {
                $final_action = (string) ($item['final_action'] ?? '');
                $final_post_id = (int) ($item['final_post_id'] ?? 0);

                if ($final_action === 'exclude') {
                    $excluded++;
                    continue;
                }

                if ($final_post_id > 0) {
                    $created++;

                    if ($final_action === 'publish') {
                        $published++;
                    } elseif ($final_action === 'schedule') {
                        $scheduled++;
                    } else {
                        $drafted++;
                    }

                    continue;
                }

                if ($final_action !== '') {
                    $failed++;
                }
            }

            return array_merge($summary, [
                'created' => $created,
                'excluded' => $excluded,
                'failed' => $failed,
                'published' => $published,
                'drafted' => $drafted,
                'scheduled' => $scheduled,
            ]);
        }

        $created_count = count($created_posts);
        $published = count(array_filter(
            $created_posts,
            static fn (array $post): bool => (string) ($post['post_status'] ?? '') === 'publish'
        ));
        $scheduled = count(array_filter(
            $created_posts,
            static fn (array $post): bool => (string) ($post['post_status'] ?? '') === 'future'
        ));
        $drafted = max(0, $created_count - $published - $scheduled);
        $approved_count = (int) ($summary['approved'] ?? 0);
        $excluded = max(0, $approved_count - $created_count);

        return array_merge($summary, [
            'created' => $created_count,
            'excluded' => $excluded,
            'failed' => (int) ($summary['failed'] ?? 0),
            'published' => $published,
            'drafted' => $drafted,
            'scheduled' => $scheduled,
        ]);
    }

    private function map_post_status_to_action(string $post_status): string
    {
        return match ($post_status) {
            'publish' => 'publish',
            'future' => 'schedule',
            default => 'draft',
        };
    }

    private function get_post_view_url(int $post_id): string
    {
        $status = get_post_status($post_id);

        if ($status === 'publish') {
            return get_permalink($post_id) ?: '';
        }

        return get_preview_post_link($post_id) ?: '';
    }

    public function get_status(): array
    {
        return [
            'module' => 'publisher',
            'status' => 'ok',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function decode_string_list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $value) ?: [])));
    }

    /**
     * @param array<int,string> $category_names
     * @return array<int,int>
     */
    private function resolve_category_ids(array $category_names): array
    {
        $ids = [];

        foreach ($category_names as $category_name) {
            $category_name = trim($category_name);
            if ($category_name === '') {
                continue;
            }

            $category = get_category_by_slug(sanitize_title($category_name));
            if (! $category) {
                $category = get_term_by('name', $category_name, 'category');
            }

            if ($category && ! is_wp_error($category)) {
                $ids[] = (int) $category->term_id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
