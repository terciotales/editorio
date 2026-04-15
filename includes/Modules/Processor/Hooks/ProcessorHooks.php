<?php

declare(strict_types=1);

namespace Editorio\Modules\Processor\Hooks;

final class ProcessorHooks
{
    public function register(): void
    {
        add_action('init', [$this, 'on_init']);
    }

    public function on_init(): void
    {
        // Reserved for processor-related WordPress hooks.
    }
}

