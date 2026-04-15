<?php

declare(strict_types=1);

namespace Editorio\Modules\Processor;

use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\Processor\Controller\ProcessorController;
use Editorio\Modules\Processor\Hooks\ProcessorHooks;
use Editorio\Modules\Processor\Repository\ProcessorRepository;
use Editorio\Modules\Processor\Service\ProcessorService;

final class ProcessorModule implements ModuleInterface
{
    private ProcessorController $controller;

    private ProcessorHooks $hooks;

    public function __construct()
    {
        $repository = new ProcessorRepository();
        $service = new ProcessorService($repository);

        $this->controller = new ProcessorController($service);
        $this->hooks = new ProcessorHooks();
    }

    public function get_slug(): string
    {
        return 'processor';
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

