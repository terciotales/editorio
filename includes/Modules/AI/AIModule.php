<?php
declare(strict_types=1);
namespace Editorio\Modules\AI;
use Editorio\Common\Contracts\ModuleInterface;
use Editorio\Modules\AI\Controller\AIController;
use Editorio\Modules\AI\Hooks\AIHooks;
use Editorio\Modules\AI\Provider\AIProviderFactory;
use Editorio\Modules\AI\Repository\AISettingsRepository;
use Editorio\Modules\AI\Service\AIService;
final class AIModule implements ModuleInterface
{
    private AIController $controller;
    private AIHooks $hooks;
    public function __construct()
    {
        $settings_repository = new AISettingsRepository();
        $provider_factory = new AIProviderFactory();
        $service = new AIService($settings_repository, $provider_factory);
        $this->controller = new AIController($service);
        $this->hooks = new AIHooks($service);
    }
    public function get_slug(): string
    {
        return 'ai';
    }
    public function register_hooks(): void
    {
        $this->hooks->register();
    }
    public function register_rest_routes(): void
    {
        $this->controller->register_routes();
    }
}
