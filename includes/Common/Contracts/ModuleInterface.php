<?php

declare(strict_types=1);

namespace Editorio\Common\Contracts;

interface ModuleInterface
{
    public function get_slug(): string;

    public function register_hooks(): void;

    public function register_rest_routes(): void;
}

