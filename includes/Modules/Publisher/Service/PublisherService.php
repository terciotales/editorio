<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher\Service;

use Editorio\Modules\Publisher\Repository\PublisherRepository;

final class PublisherService
{
    private PublisherRepository $repository;

    public function __construct(PublisherRepository $repository)
    {
        $this->repository = $repository;
    }

    public function get_status(): array
    {
        return [
            'module' => 'publisher',
            'status' => 'ok',
            'items' => $this->repository->count(),
        ];
    }
}

