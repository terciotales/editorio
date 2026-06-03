<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher\Service;

use Editorio\Modules\Collector\Repository\CollectorRepository;
use Editorio\Modules\Collector\Service\CollectorService;
use Editorio\Modules\AI\Service\AIService;
use Editorio\Modules\Sources\Repository\SourcesRepository;
use Editorio\Modules\Publisher\Repository\PublisherRepository;
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
        string $generated_content = ''
    ): array|WP_Error {
        $status = $approved ? 'approved' : 'rejected';

        $session = $this->repository->get_session($session_id);
        
        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $this->repository->update_item_approval($session_id, $item_id, $status, $generated_title, $generated_content);

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

        return [
            'session_id' => $session_id,
            'item_id' => $item_id,
            'status' => $status,
            'current_index' => $current_index,
            'total_items' => count($selected_items),
            'next_item' => $next_item,
            'approved_count' => $session['approved_count'],
            'rejected_count' => $session['rejected_count'],
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
            'stage' => 'confirmation',
        ];
    }

    /**
     * Salva items aprovados como drafts do WordPress
     */
    public function save_approved_drafts(string $session_id, array $final_approved_ids = []): array|WP_Error
    {
        $session = $this->repository->get_session($session_id);
        
        if (!$session) {
            return new WP_Error('session_not_found', 'Workflow session not found');
        }

        $items = $this->repository->get_approval_summary($session_id);
        
        $to_save = empty($final_approved_ids) 
            ? array_filter($items, fn($item) => $item['approval_status'] === 'approved')
            : array_filter($items, fn($item) => in_array($item['id'], $final_approved_ids));

        $created_posts = [];
        
        foreach ($to_save as $item) {
            $post_id = wp_insert_post([
                'post_type' => 'post',
                'post_status' => 'draft',
                'post_title' => $item['generated_title'] ?? 'Untitled',
                'post_content' => $item['generated_content'] ?? '',
                'post_author' => $session['user_id'],
                'meta_input' => [
                    '_editorio_workflow_item_id' => $item['id'],
                    '_editorio_session_id' => $session_id,
                ],
            ]);

            if (!is_wp_error($post_id)) {
                $created_posts[] = [
                    'workflow_item_id' => $item['id'],
                    'post_id' => $post_id,
                    'title' => $item['generated_title'] ?? 'Untitled',
                ];
            }
        }

        $this->repository->update_stage($session_id, 'completed');

        return [
            'session_id' => $session_id,
            'created_posts' => $created_posts,
            'total_created' => count($created_posts),
            'stage' => 'completed',
        ];
    }

    public function get_status(): array
    {
        return [
            'module' => 'publisher',
            'status' => 'ok',
        ];
    }
}
