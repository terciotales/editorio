<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher\Hooks;

final class PublisherHooks
{
    public function register(): void
    {
        add_action('init', [$this, 'on_init']);
    }

    public function on_init(): void
    {
        // Reserved for publisher-related WordPress hooks.
    }
}

