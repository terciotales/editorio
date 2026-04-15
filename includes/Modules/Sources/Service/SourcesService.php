<?php

declare(strict_types=1);

namespace Editorio\Modules\Sources\Service;

use Editorio\Modules\Sources\Repository\SourcesRepository;

final class SourcesService
{
    private SourcesRepository $repository;

    public function __construct(SourcesRepository $repository)
    {
        $this->repository = $repository;
    }

    public function get_status(): array
    {
        return [
            'module' => 'sources',
            'status' => 'ok',
            'items' => $this->repository->count(),
        ];
    }
}

