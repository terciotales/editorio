<?php

declare(strict_types=1);

namespace Editorio\Modules\Sources\Hooks;

final class SourcesHooks
{
    private const MENU_SLUG = 'editorio-sources';

    public function register(): void
    {
        add_action('init', [$this, 'on_init']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function on_init(): void
    {
        // Reserved for source-related WordPress hooks.
    }

    public function register_admin_menu(): void
    {
        add_menu_page(
            __('Editorio', 'editorio'),
            __('Editorio', 'editorio'),
            'edit_posts',
            self::MENU_SLUG,
            [$this, 'render_sources_page'],
            'dashicons-rss',
            58
        );
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        if (wp_script_is('editorio-sources', 'registered')) {
            $config = [
                'restNamespace' => '/editorio/v1',
                'nonce' => wp_create_nonce('wp_rest'),
            ];

            wp_add_inline_script(
                'editorio-sources',
                'window.editorioSourcesConfig = ' . wp_json_encode($config) . ';',
                'before'
            );

            wp_enqueue_script('editorio-sources');
        }

        if (wp_style_is('editorio-sources', 'registered')) {
            wp_enqueue_style('editorio-sources');
        }
    }

    public function render_sources_page(): void
    {
        if (! current_user_can('edit_posts')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Sources', 'editorio') . '</h1>';
        echo '<div id="editorio-sources-app"></div>';
        echo '</div>';
    }
}
