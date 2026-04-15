<?php

declare(strict_types=1);

namespace Editorio\Modules\Draft;

use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\Draft\Controller\DraftController;
use Editorio\Modules\Draft\Hooks\DraftHooks;
use Editorio\Modules\Draft\Repository\DraftRepository;
use Editorio\Modules\Draft\Service\DraftService;

final class DraftModule implements ModuleInterface
{
    private DraftController $controller;

    private DraftHooks $hooks;

    public function __construct()
    {
        $repository = new DraftRepository();
        $service = new DraftService($repository);

        $this->controller = new DraftController($service);
        $this->hooks = new DraftHooks();
    }

    public function get_slug(): string
    {
        return 'draft';
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
