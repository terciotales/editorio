<?php

declare(strict_types=1);

namespace Editorio\Common;

final class AdminMenu
{
    private const PARENT_MENU_SLUG = 'editorio';
    private const PUBLISHER_MENU_SLUG = 'editorio-publisher';
    private const SOURCES_MENU_SLUG = 'editorio-sources';
    private const AI_MENU_SLUG = 'editorio-ai';

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
    }

    public function register_admin_menu(): void
    {
        add_menu_page(
            __('Editorio', 'editorio'),
            __('Editorio', 'editorio'),
            'edit_posts',
            self::PARENT_MENU_SLUG,
            [$this, 'render_publisher_page'],
            $this->get_menu_icon(),
            58
        );

        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('Publicar', 'editorio'),
            __('Publicar', 'editorio'),
            'edit_posts',
            self::PUBLISHER_MENU_SLUG,
            [$this, 'render_publisher_page']
        );

        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('Fontes', 'editorio'),
            __('Fontes', 'editorio'),
            'edit_posts',
            self::SOURCES_MENU_SLUG,
            [$this, 'render_sources_page']
        );

        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('IA', 'editorio'),
            __('IA', 'editorio'),
            'manage_options',
            self::AI_MENU_SLUG,
            [$this, 'render_ai_settings_page']
        );

        remove_submenu_page(self::PARENT_MENU_SLUG, self::PARENT_MENU_SLUG);
    }

    public function render_publisher_page(): void
    {
        if (! current_user_can('edit_posts')) {
            return;
        }

        $this->render_shell('editorio-publisher-page-shell', 'editorio-publisher-root');
    }

    public function render_sources_page(): void
    {
        if (! current_user_can('edit_posts')) {
            return;
        }

        $this->render_shell('editorio-sources-page-shell', 'editorio-sources-app');
    }

    public function render_ai_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $this->render_shell('editorio-ai-settings', 'editorio-ai-settings-react');
    }

    private function render_shell(string $shell_class, string $root_id): void
    {
        echo '<div class="wrap ' . esc_attr($shell_class) . ' boot-layout-container">';
        echo '<style>';
        echo '.' . esc_attr($shell_class) . '{margin:0!important;max-width:none;padding:0;width:100%}';
        echo '#wpcontent{padding-inline-start:0}';
        echo '#wpbody-content{padding-bottom:0}';
        echo '</style>';
        echo '<div id="' . esc_attr($root_id) . '"></div>';
        echo '</div>';
    }

    private function get_menu_icon(): string
    {
        $svg = <<<'SVG'
<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
    <path fill="black" d="M4 3h12a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm2 3v2h8V6H6zm0 4v2h8v-2H6zm0 4v2h5v-2H6z"/>
    <circle cx="14.5" cy="15" r="1.5" fill="black"/>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
