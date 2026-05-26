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
            __('Fontes', 'editorio'),
            __('Fontes', 'editorio'),
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
                'messages' => [
                    'pageTitle' => __('Fontes', 'editorio'),
                    'pageSubtitle' => __('Gerencie feeds, status e conteúdo de origem em um painel mais organizado.', 'editorio'),
                    'overview' => __('Use o formulário abaixo para cadastrar, editar e ativar fontes de conteúdo.', 'editorio'),
                    'addSource' => __('Adicionar fonte', 'editorio'),
                    'totalLabel' => __('Total de fontes', 'editorio'),
                    'activeLabel' => __('Fontes ativas', 'editorio'),
                    'createTitle' => __('Nova fonte', 'editorio'),
                    'editTitle' => __('Editar fonte', 'editorio'),
                    'formHint' => __('Preencha os dados do feed para manter a lista fácil de revisar.', 'editorio'),
                    'nameLabel' => __('Nome', 'editorio'),
                    'feedLabel' => __('Feed URL', 'editorio'),
                    'activeLabelToggle' => __('Fonte ativa', 'editorio'),
                    'activeHelp' => __('Fontes ativas podem ser usadas no processamento.', 'editorio'),
                    'saving' => __('Salvando...', 'editorio'),
                    'create' => __('Criar', 'editorio'),
                    'update' => __('Atualizar', 'editorio'),
                    'cancel' => __('Cancelar', 'editorio'),
                    'listTitle' => __('Fontes cadastradas', 'editorio'),
                    'listHint' => __('Edite, desative ou remova itens sem sair da página.', 'editorio'),
                    'emptyState' => __('Nenhuma fonte cadastrada ainda. Crie a primeira acima.', 'editorio'),
                    'idColumn' => __('ID', 'editorio'),
                    'nameColumn' => __('Nome', 'editorio'),
                    'feedColumn' => __('Feed URL', 'editorio'),
                    'statusColumn' => __('Status', 'editorio'),
                    'actionsColumn' => __('Ações', 'editorio'),
                    'statusActive' => __('Ativa', 'editorio'),
                    'statusInactive' => __('Inativa', 'editorio'),
                    'statusToggleLabel' => __('Ativar fonte', 'editorio'),
                    'edit' => __('Editar', 'editorio'),
                    'delete' => __('Excluir', 'editorio'),
                    'refresh' => __('Atualizar', 'editorio'),
                    'docs' => __('Ver exemplo', 'editorio'),
                    'loadError' => __('Não foi possível carregar as fontes.', 'editorio'),
                    'saveError' => __('Não foi possível salvar a fonte.', 'editorio'),
                    'deleteError' => __('Não foi possível excluir a fonte.', 'editorio'),
                    'deleteConfirm' => __('Tem certeza que deseja excluir esta fonte?', 'editorio'),
                    'createdSuccess' => __('Fonte criada com sucesso.', 'editorio'),
                    'updatedSuccess' => __('Fonte atualizada com sucesso.', 'editorio'),
                    'deletedSuccess' => __('Fonte excluída com sucesso.', 'editorio'),
                ],
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

        echo '<div class="wrap editorio-sources-page-shell boot-layout-container">';
        echo '<style>';
        echo '.editorio-sources-page-shell{margin:0!important;max-width:none;padding:0;width:100%}';
        echo '#wpcontent{padding-inline-start:0}';
        echo '#wpbody-content{padding-bottom:0}';
        echo '</style>';
        echo '<div id="editorio-sources-app"></div>';
        echo '</div>';
    }
}
