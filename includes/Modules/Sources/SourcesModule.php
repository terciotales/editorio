<?php

declare(strict_types=1);

namespace Editorio\Modules\Sources;

use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\Sources\Controller\SourcesController;
use Editorio\Modules\Sources\Hooks\SourcesHooks;
use Editorio\Modules\Sources\Repository\SourcesRepository;
use Editorio\Modules\Sources\Service\SourcesService;

final class SourcesModule implements ModuleInterface
{
    private SourcesController $controller;

    private SourcesHooks $hooks;

    private SourcesService $service;

    public function __construct()
    {
        $repository = new SourcesRepository();
        $this->service = new SourcesService($repository);

        $this->controller = new SourcesController($this->service);
        $this->hooks = new SourcesHooks();
    }

    public static function activate(): void
    {
        $repository = new SourcesRepository();
        $service = new SourcesService($repository);
        $service->install();
    }

    public function get_slug(): string
    {
        return 'sources';
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
