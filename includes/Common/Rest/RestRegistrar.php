<?php

declare(strict_types=1);

namespace Editorio\Common\Rest;

final class RestRegistrar
{
    public function register(string $namespace, string $route, array $args): void
    {
        register_rest_route($namespace, $route, $args);
    }
}

