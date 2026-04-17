<?php
declare(strict_types=1);
namespace Editorio\Modules\AI\Provider;
use Editorio\Modules\AI\Contracts\AIProviderInterface;
use RuntimeException;
final class AIProviderFactory
{
    /**
     * @param array<string,mixed> $settings
     */
    public function create(array $settings): AIProviderInterface
    {
        $provider = strtolower((string) ($settings['provider'] ?? 'openai'));
        if ($provider !== 'openai') {
            throw new RuntimeException(sprintf(__('Provider "%s" is not implemented yet.', 'editorio'), $provider));
        }
        return new OpenAIProvider($settings);
    }
}
