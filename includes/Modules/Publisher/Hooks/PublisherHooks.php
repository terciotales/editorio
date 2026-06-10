<?php

declare(strict_types=1);

namespace Editorio\Modules\Publisher\Hooks;

use Editorio\Modules\Publisher\Repository\PublisherRepository;

final class PublisherHooks
{
    private const PARENT_MENU_SLUG = 'editorio';
    private const MENU_SLUG = 'editorio-publisher';

    public function register(): void
    {
        add_action('editorio_install_tables', [$this, 'install_tables']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function install_tables(): void
    {
        $repository = new PublisherRepository();
        $repository->install();
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'toplevel_page_' . self::PARENT_MENU_SLUG
            && $hook_suffix !== 'editorio_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_media();

        if (wp_script_is('editorio-publisher', 'registered')) {
            $config = [
                'restNamespace' => '/editorio/v1',
                'nonce' => wp_create_nonce('wp_rest'),
                'messages' => [
                    'pageTitle' => __('Publicar Notícias', 'editorio'),
                    'pageSubtitle' => __('Colete, selecione, revise e publique notícias de suas fontes.', 'editorio'),
                    'activeSourcesLabel' => __('Fontes ativas', 'editorio'),
                    'activeSourcesTitle' => __('Fontes que entram na coleta', 'editorio'),
                    'activeSourcesHint' => __('Essas fontes serão usadas quando o processo começar.', 'editorio'),
                    'activeSourcesLoading' => __('Carregando fontes ativas...', 'editorio'),
                    'activeSourcesLoadError' => __('Não foi possível carregar as fontes ativas.', 'editorio'),
                    'activeSourcesEmpty' => __('Nenhuma fonte está ativa agora. Ative fontes em Editorio > Fontes antes de iniciar.', 'editorio'),
                ],
            ];

            wp_enqueue_script('editorio-publisher');
            wp_localize_script('editorio-publisher', 'editorioPublisher', $config);

            if (wp_style_is('editorio-publisher', 'registered')) {
                wp_enqueue_style('editorio-publisher');
            }
        }
    }
}
