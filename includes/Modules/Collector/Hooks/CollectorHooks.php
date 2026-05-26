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
        add_action('editorio_install', [$this, 'on_install']);
    }

    public function on_init(): void
    {
        // Initialize collector-related WordPress hooks.
        // Future: Schedule periodic feed collection if enabled.
    }

    public function on_install(): void
    {
        if ($this->service) {
            $this->service->install();
        }
    }
}

