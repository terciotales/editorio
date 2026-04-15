<?php

declare(strict_types=1);

namespace Editorio;

use Editorio\Common\Assets;
use Editorio\Common\ModuleLoader;
use Editorio\Modules\Collector\CollectorModule;
use Editorio\Modules\Draft\DraftModule;
use Editorio\Modules\Processor\ProcessorModule;
use Editorio\Modules\Publisher\PublisherModule;
use Editorio\Modules\Review\ReviewModule;
use Editorio\Modules\Sources\SourcesModule;

final class Plugin
{
    private static ?self $instance = null;

    private ModuleLoader $module_loader;

    private Assets $assets;

    private function __construct()
    {
        $this->module_loader = new ModuleLoader([
            new SourcesModule(),
            new CollectorModule(),
            new ProcessorModule(),
            new DraftModule(),
            new ReviewModule(),
            new PublisherModule(),
        ]);

        $this->assets = new Assets();
    }

    public static function boot(): void
    {
        if (self::$instance instanceof self) {
            return;
        }

        self::$instance = new self();
        self::$instance->register();
    }

    public static function activate(): void
    {
        SourcesModule::activate();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    private function register(): void
    {
        $this->assets->register_hooks();
        $this->module_loader->register_hooks();
        add_action('rest_api_init', [$this->module_loader, 'register_rest_routes']);
    }
}
