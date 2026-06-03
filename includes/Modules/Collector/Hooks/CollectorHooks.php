<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Hooks;

use Editorio\Modules\Collector\Service\CollectorService;

final class CollectorHooks
{
    private ?CollectorService $service = null;

    public function set_service(CollectorService $service): void
    {
        $this->service = $service;
    }

    public function register(): void
    {
        add_action('init', [$this, 'on_init']);
        add_action('editorio_collector_sync', [$this, 'on_sync']);
        add_action('editorio_collector_sync_source', [$this, 'on_sync_source'], 10, 1);
    }

    public function on_init(): void
    {
        // Reserved for collector-related runtime hooks.
    }

    public function on_sync(): void
    {
        if ($this->service) {
            $this->service->process_pending_batch(5);
        }
    }

    public function on_sync_source(int $source_id): void
    {
        if ($this->service) {
            $this->service->collect_source($source_id);
        }
    }
}
