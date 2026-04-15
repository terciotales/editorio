<?php

declare(strict_types=1);

namespace Editorio\Modules\Review;

use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\Review\Controller\ReviewController;
use Editorio\Modules\Review\Hooks\ReviewHooks;
use Editorio\Modules\Review\Repository\ReviewRepository;
use Editorio\Modules\Review\Service\ReviewService;

final class ReviewModule implements ModuleInterface
{
    private ReviewController $controller;

    private ReviewHooks $hooks;

    public function __construct()
    {
        $repository = new ReviewRepository();
        $service = new ReviewService($repository);

        $this->controller = new ReviewController($service);
        $this->hooks = new ReviewHooks();
    }

    public function get_slug(): string
    {
        return 'review';
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

