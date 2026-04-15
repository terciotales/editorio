<?php

declare(strict_types=1);

namespace Editorio\Modules\Processor\Service;

use Editorio\Modules\Processor\Repository\ProcessorRepository;

final class ProcessorService
{
    private ProcessorRepository $repository;

    public function __construct(ProcessorRepository $repository)
    {
        $this->repository = $repository;
    }

    public function get_status(): array
    {
        return [
            'module' => 'processor',
            'status' => 'ok',
            'items' => $this->repository->count(),
        ];
    }
}

