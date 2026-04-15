<?php

declare(strict_types=1);

namespace Editorio\Modules\Draft\Hooks;

final class DraftHooks
{
    public function register(): void
    {
        add_action('init', [$this, 'on_init']);
    }

    public function on_init(): void
    {
        // Reserved for draft-related WordPress hooks.
    }
}

