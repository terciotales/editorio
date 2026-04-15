<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Service;

use Editorio\Modules\Collector\Repository\CollectorRepository;

final class CollectorService
{
    private CollectorRepository $repository;

    public function __construct(CollectorRepository $repository)
    {
        $this->repository = $repository;
    }

    public function get_status(): array
    {
        return [
            'module' => 'collector',
            'status' => 'ok',
            'items' => $this->repository->count(),
        ];
    }
}

