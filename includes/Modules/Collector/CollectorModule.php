<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector;

use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\Collector\Controller\CollectorController;
use Editorio\Modules\Collector\Hooks\CollectorHooks;
use Editorio\Modules\Collector\Repository\CollectorRepository;
use Editorio\Modules\Collector\Service\CollectorService;

final class CollectorModule implements ModuleInterface
{
    private CollectorController $controller;

    private CollectorHooks $hooks;

    private CollectorService $service;

    public function __construct()
    {
        $repository = new CollectorRepository();
        $this->service = new CollectorService($repository);

        $this->controller = new CollectorController($this->service);
        $this->hooks = new CollectorHooks();
        $this->hooks->set_service($this->service);
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

