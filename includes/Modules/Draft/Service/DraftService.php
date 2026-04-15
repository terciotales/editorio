<?php

declare(strict_types=1);

namespace Editorio\Modules\Draft\Service;

use Editorio\Modules\Draft\Repository\DraftRepository;

final class DraftService
{
    private DraftRepository $repository;

    public function __construct(DraftRepository $repository)
    {
        $this->repository = $repository;
    }

    public function get_status(): array
    {
        return [
            'module' => 'draft',
            'status' => 'ok',
            'items' => $this->repository->count(),
        ];
    }
}

