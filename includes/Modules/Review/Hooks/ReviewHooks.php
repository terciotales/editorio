<?php

declare(strict_types=1);

namespace Editorio\Modules\Review\Hooks;

final class ReviewHooks
{
    public function register(): void
    {
        add_action('init', [$this, 'on_init']);
    }

    public function on_init(): void
    {
        // Reserved for review-related WordPress hooks.
    }
}

