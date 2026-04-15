<?php

declare(strict_types=1);

namespace Editorio\Common;

final class Assets
{
    public function register_hooks(): void
    {
        add_action('init', [$this, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor']);
    }

    public function register(): void
    {
        $script_asset_path = EDITORIO_PLUGIN_PATH . 'bundle/js/index.asset.php';
        $script_file_path = EDITORIO_PLUGIN_PATH . 'bundle/js/index.js';
        $style_file_path = EDITORIO_PLUGIN_PATH . 'bundle/css/index.css';

        $script_asset = [
            'dependencies' => [],
            'version' => EDITORIO_VERSION,
        ];

        if (file_exists($script_asset_path)) {
            $script_asset_data = require $script_asset_path;
            if (is_array($script_asset_data)) {
                $script_asset = array_merge($script_asset, $script_asset_data);
            }
        }

        if (file_exists($script_file_path)) {
            wp_register_script(
                'editorio-app',
                EDITORIO_PLUGIN_URL . 'bundle/js/index.js',
                $script_asset['dependencies'],
                $script_asset['version'],
                true
            );
        }

        if (file_exists($style_file_path)) {
            wp_register_style(
                'editorio-app',
                EDITORIO_PLUGIN_URL . 'bundle/css/index.css',
                [],
                EDITORIO_VERSION
            );
        }
    }

    public function enqueue_admin(): void
    {
        if (wp_script_is('editorio-app', 'registered')) {
            wp_enqueue_script('editorio-app');
        }

        if (wp_style_is('editorio-app', 'registered')) {
            wp_enqueue_style('editorio-app');
        }
    }

    public function enqueue_editor(): void
    {
        if (wp_script_is('editorio-app', 'registered')) {
            wp_enqueue_script('editorio-app');
        }

        if (wp_style_is('editorio-app', 'registered')) {
            wp_enqueue_style('editorio-app');
        }
    }
}

