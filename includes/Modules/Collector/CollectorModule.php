<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector;

use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\Collector\Adapter\JsonFeedAdapter;
use Editorio\Modules\Collector\Adapter\XmlFeedAdapter;
use Editorio\Modules\Collector\Controller\CollectorController;
use Editorio\Modules\Collector\Hooks\CollectorHooks;
use Editorio\Modules\Collector\Repository\CollectorRepository;
use Editorio\Modules\Collector\Repository\CollectorSyncRepository;
use Editorio\Modules\Collector\Service\CollectorService;
use Editorio\Modules\Sources\Repository\SourcesRepository;

final class CollectorModule implements ModuleInterface
{
    private CollectorController $controller;

    private CollectorHooks $hooks;

    private CollectorService $service;

    public function __construct()
    {
        $repository = new CollectorRepository();
        $sources_repository = new SourcesRepository();
        $sync_repository = new CollectorSyncRepository();
        $this->service = new CollectorService(
            $repository,
            $sources_repository,
            $sync_repository,
            [
                new JsonFeedAdapter(),
                new XmlFeedAdapter(),
            ]
        );

        $this->controller = new CollectorController($this->service);
        $this->hooks = new CollectorHooks();
        $this->hooks->set_service($this->service);
    }

    public static function activate(): void
    {
        $repository = new CollectorRepository();
        $sources_repository = new SourcesRepository();
        $sync_repository = new CollectorSyncRepository();
        $service = new CollectorService($repository, $sources_repository, $sync_repository);
        $service->install();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('editorio_collector_sync');
    }

    public function get_slug(): string
    {
        return 'collector';
    }

    public function register_hooks(): void
    {
        $this->hooks->register();
    }

    public function register_rest_routes(): void
    {
        $this->controller->register_routes();
    }
}
