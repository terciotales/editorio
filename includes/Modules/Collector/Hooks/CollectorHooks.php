<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Hooks;

final class CollectorHooks
{
    public function register(): void
    {
        add_action('init', [$this, 'on_init']);
    }

    public function on_init(): void
    {
        // Reserved for collector-related WordPress hooks.
    }
}

