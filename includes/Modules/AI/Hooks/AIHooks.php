<?php
declare(strict_types=1);
namespace Editorio\Modules\AI\Hooks;
use Editorio\Modules\AI\Service\AIService;

final class AIHooks
{
    private const PARENT_MENU_SLUG = 'editorio';
    private const MENU_SLUG = 'editorio-ai';
    private AIService $service;

    public function __construct(AIService $service)
    {
        $this->service = $service;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function register_admin_menu(): void
    {
        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('IA', 'editorio'),
            __('IA', 'editorio'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        $is_target_page = isset($_GET['page']) && sanitize_key(wp_unslash((string) $_GET['page'])) === self::MENU_SLUG;
        if (! $is_target_page && $hook_suffix !== 'editorio_page_' . self::MENU_SLUG) {
            return;
        }

        $settings = $this->service->get_settings();
        $config = [
            'restUrl' => rest_url('editorio/v1/ai/settings'),
            'nonce' => wp_create_nonce('wp_rest'),
            'settings' => [
                'enabled' => ! empty($settings['enabled']),
                'rewrite_prompt' => (string) ($settings['rewrite_prompt'] ?? ''),
                'curation_prompt' => (string) ($settings['curation_prompt'] ?? ''),
            ],
            'dependency' => $this->service->get_dependency_status(),
            'messages' => [
                'pageTitle' => __('IA', 'editorio'),
                'pageSubtitle' => __('Configure recursos de IA para o Editorio usando WordPress AI.', 'editorio'),
                'toggleLabel' => __('Ativar IA no Editorio', 'editorio'),
                'toggleHelp' => __('Usar IA durante processamento de conteúdo.', 'editorio'),
                'toggleEnabled' => __('AI enabled.', 'editorio'),
                'toggleDisabled' => __('AI disabled.', 'editorio'),
                'rewritePromptLabel' => __('Prompt de reescrita', 'editorio'),
                'rewritePromptHelp' => __('Usado para reescrever notícias. Provedor e credenciais vêm do WordPress AI.', 'editorio'),
                'rewritePromptSaved' => __('Prompt de reescrita salvo.', 'editorio'),
                'curationPromptLabel' => __('Prompt de curadoria', 'editorio'),
                'curationPromptHelp' => __('Usado apenas como nota editorial da síntese. A estrutura da curadoria permanece fixa e sempre gera novas pautas com fontes citadas.', 'editorio'),
                'curationPromptSaved' => __('Prompt de curadoria salvo.', 'editorio'),
                'saveError' => __('Falha ao salvar configurações.', 'editorio'),
                'saveRewritePrompt' => __('Salvar prompt de reescrita', 'editorio'),
                'saveCurationPrompt' => __('Salvar prompt de curadoria', 'editorio'),
                'manageConnectors' => __('Gerenciar conectores', 'editorio'),
                'dependencyMissing' => __('Plugin WordPress AI não está ativo. Ative o plugin "AI" para habilitar este módulo.', 'editorio'),
                'dependencyNoCredentials' => __('Nenhum conector de IA está configurado. Configure um provedor na tela de conectores.', 'editorio'),
                'dependencyInvalidCredentials' => __('Os conectores configurados não estão válidos para geração de texto.', 'editorio'),
                'dependencyReady' => __('WordPress AI ativo e com conectores disponíveis.', 'editorio'),
            ],
        ];

        if (wp_script_is('editorio-ai-settings', 'registered')) {
            wp_add_inline_script(
                'editorio-ai-settings',
                'window.editorioAiConfig = ' . wp_json_encode($config) . ';',
                'before'
            );
            wp_enqueue_script('editorio-ai-settings');
        }

        if (wp_style_is('editorio-ai-settings', 'registered')) {
            wp_enqueue_style('editorio-ai-settings');
        }

        wp_enqueue_style('wp-components');
    }
    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap editorio-ai-settings boot-layout-container">
            <style>
                .editorio-ai-settings{margin:0!important;max-width:none;padding:0;width:100%}
                #wpcontent{padding-inline-start:0}
                #wpbody-content{padding-bottom:0}
            </style>
            <div id="editorio-ai-settings-react"></div>
        </div>
        <?php
    }
}
