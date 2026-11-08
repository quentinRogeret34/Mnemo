<?php

namespace App\Llm;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Single place that says which platform and model the application uses for chat and for embeddings.
 */
final class LlmSettings
{
    public const PLATFORMS = ['mistral', 'openrouter'];

    /**
     * @param ServiceLocator<PlatformInterface> $platforms
     */
    public function __construct(
        #[AutowireLocator(['mistral' => new Autowire(service: 'ai.platform.mistral'), 'openrouter' => new Autowire(service: 'ai.platform.openrouter')])]
        private readonly ServiceLocator $platforms,
        #[Autowire(env: 'LLM_PLATFORM')]
        public readonly string $chatPlatform,
        #[Autowire(env: 'LLM_MODEL')]
        public readonly string $chatModel,
        #[Autowire(env: 'EMBEDDING_PLATFORM')]
        public readonly string $embeddingPlatform,
        #[Autowire(env: 'EMBEDDING_MODEL')]
        public readonly string $embeddingModel,
        #[Autowire(env: 'MISTRAL_API_KEY')]
        private readonly string $mistralKey,
        #[Autowire(env: 'OPENROUTER_API_KEY')]
        private readonly string $openRouterKey,
    ) {
    }

    public function platform(string $name): PlatformInterface
    {
        if (!$this->platforms->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Unknown LLM platform "%s", expected one of: %s.', $name, implode(', ', self::PLATFORMS)));
        }

        return $this->platforms->get($name);
    }

    public function isConfigured(string $platform): bool
    {
        return '' !== trim(match ($platform) {
            'mistral' => $this->mistralKey,
            'openrouter' => $this->openRouterKey,
            default => '',
        });
    }
}
