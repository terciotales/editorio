<?php

declare(strict_types=1);

namespace Editorio\Common;

use Editorio\Common\Contracts\ModuleInterface;

final class ModuleLoader
{
    /**
     * @var ModuleInterface[]
     */
    private array $modules;

    /**
     * @param ModuleInterface[] $modules
     */
    public function __construct(array $modules)
    {
        $this->modules = $modules;
    }

    public function register_hooks(): void
    {
        foreach ($this->modules as $module) {
            $module->register_hooks();
        }
    }

    public function register_rest_routes(): void
    {
        foreach ($this->modules as $module) {
            $module->register_rest_routes();
        }
    }
}

