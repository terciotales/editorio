<?php

declare(strict_types=1);

namespace Editorio\Modules\Review\Service;

use Editorio\Modules\Review\Repository\ReviewRepository;

final class ReviewService
{
    private ReviewRepository $repository;

    public function __construct(ReviewRepository $repository)
    {
        $this->repository = $repository;
    }

    public function get_status(): array
    {
        return [
            'module' => 'review',
            'status' => 'ok',
            'items' => $this->repository->count(),
        ];
    }
}

