<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher;

use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\Publisher\Controller\PublisherController;
use Editorio\Modules\Publisher\Hooks\PublisherHooks;
use Editorio\Modules\Publisher\Repository\PublisherRepository;
use Editorio\Modules\Publisher\Service\PublisherService;

final class PublisherModule implements ModuleInterface
{
    private PublisherController $controller;

    private PublisherHooks $hooks;

    public function __construct()
    {
        $repository = new PublisherRepository();
        $service = new PublisherService($repository);

        $this->controller = new PublisherController($service);
        $this->hooks = new PublisherHooks();
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

