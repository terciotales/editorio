<?php

declare(strict_types=1);

namespace Editorio\Modules\Sources\Hooks;

final class SourcesHooks
{
    public function register(): void
    {
        add_action('init', [$this, 'on_init']);
    }

    public function on_init(): void
    {
        // Reserved for source-related WordPress hooks.
    }
}

