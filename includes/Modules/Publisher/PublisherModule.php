<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher;

use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\Collector\Repository\CollectorRepository;
use Editorio\Modules\Collector\Repository\CollectorSyncRepository;
use Editorio\Modules\Collector\Service\CollectorService;
use Editorio\Modules\AI\Repository\AISettingsRepository;
use Editorio\Modules\AI\Service\AIService;
use Editorio\Modules\Publisher\Controller\PublisherController;
use Editorio\Modules\Publisher\Hooks\PublisherHooks;
use Editorio\Modules\Publisher\Repository\PublisherRepository;
use Editorio\Modules\Publisher\Service\PublisherService;
use Editorio\Modules\Sources\Repository\SourcesRepository;

final class PublisherModule implements ModuleInterface
{
    private PublisherController $controller;

    private PublisherHooks $hooks;

    public function __construct()
    {
        $publisher_repository = new PublisherRepository();
        $collector_repository = new CollectorRepository();
        $collector_sync_repository = new CollectorSyncRepository();
        $sources_repository = new SourcesRepository();
        $ai_settings_repository = new AISettingsRepository();
        $ai_service = new AIService($ai_settings_repository);
        
        $collector_service = new CollectorService(
            $collector_repository,
            $sources_repository,
            $collector_sync_repository
        );
        
        $service = new PublisherService(
            $publisher_repository,
            $collector_service,
            $collector_repository,
            $sources_repository,
            $ai_service
        );

        $this->controller = new PublisherController($service);
        $this->hooks = new PublisherHooks();
    }

    public static function activate(): void
    {
        $publisher_repository = new PublisherRepository();
        $publisher_repository->install();
    }

    public function get_slug(): string
    {
        return 'publisher';
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
