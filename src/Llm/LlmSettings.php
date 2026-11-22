<?php

namespace App\Llm;

use App\Entity\Project;
use App\Entity\Setting;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Bridge\OpenRouter\Factory;
use Symfony\AI\Platform\Bridge\OpenRouter\ModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Single place that says which platform, key and models the application uses for chat and for embeddings.
 * The key and the models set from the interface win over the environment; a project may name its own models.
 */
final class LlmSettings implements ResetInterface
{
    public const PLATFORMS = ['openrouter'];
    public const KEY = 'llm.openrouter_key';
    public const CHAT_MODEL = 'llm.chat_model';
    public const EMBEDDING_MODEL = 'llm.embedding_model';

    /** @var array<string, string>|null */
    private ?array $stored = null;

    /** @var array<string, PlatformInterface> */
    private array $keyed = [];

    /**
     * @param ServiceLocator<PlatformInterface> $platforms
     */
    public function __construct(
        #[AutowireLocator(['openrouter' => new Autowire(service: 'ai.platform.openrouter')])]
        private readonly ServiceLocator $platforms,
        #[Autowire(env: 'LLM_PLATFORM')]
        public readonly string $chatPlatform,
        #[Autowire(env: 'LLM_MODEL')]
        public readonly string $defaultChatModel,
        #[Autowire(env: 'EMBEDDING_PLATFORM')]
        public readonly string $embeddingPlatform,
        #[Autowire(env: 'EMBEDDING_MODEL')]
        public readonly string $defaultEmbeddingModel,
        #[Autowire(env: 'OPENROUTER_API_KEY')]
        #[\SensitiveParameter]
        private readonly string $openRouterKey,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?SecretBox $secretBox = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function chatModel(?Project $project = null): string
    {
        return $project?->getChatModel() ?? $this->stored()[self::CHAT_MODEL] ?? $this->defaultChatModel;
    }

    public function embeddingModel(?Project $project = null): string
    {
        return $project?->getEmbeddingModel() ?? $this->stored()[self::EMBEDDING_MODEL] ?? $this->defaultEmbeddingModel;
    }

    /**
     * @param string|null $key a key to try before it is saved; the configured one otherwise
     */
    public function platform(string $name, #[\SensitiveParameter] ?string $key = null): PlatformInterface
    {
        if (!$this->platforms->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Unknown LLM platform "%s", expected one of: %s.', $name, implode(', ', self::PLATFORMS)));
        }
        $key ??= $this->storedKey();

        return null === $key
            ? $this->platforms->get($name)
            : $this->keyed[hash('sha256', $key)] ??= Factory::createPlatform($key, $this->httpClient, eventDispatcher: $this->eventDispatcher);
    }

    /**
     * Embedding models the OpenRouter bridge knows: any other id would be sent to the chat endpoint.
     *
     * @return list<string>
     */
    public function embeddingModels(): array
    {
        return array_keys(array_filter((new ModelCatalog())->getModels(), static fn (array $model): bool => EmbeddingsModel::class === $model['class']));
    }

    public function isConfigured(string $platform): bool
    {
        return 'openrouter' === $platform && (null !== $this->storedKey() || '' !== trim($this->openRouterKey));
    }

    /**
     * @return string|null "interface", "environment", or null when there is no key
     */
    public function keySource(): ?string
    {
        return null !== $this->storedKey() ? 'interface' : ('' !== trim($this->openRouterKey) ? 'environment' : null);
    }

    /**
     * The worker lives longer than a change made in the interface: the stored settings are read again for each message.
     */
    public function reset(): void
    {
        $this->stored = null;
        $this->keyed = [];
    }

    private function storedKey(): ?string
    {
        $encrypted = $this->stored()[self::KEY] ?? null;

        return null === $encrypted || null === $this->secretBox ? null : $this->secretBox->decrypt($encrypted);
    }

    /**
     * @return array<string, string>
     */
    private function stored(): array
    {
        if (null === $this->stored) {
            $this->stored = [];
            foreach ($this->entityManager?->getRepository(Setting::class)->findBy(['name' => [self::KEY, self::CHAT_MODEL, self::EMBEDDING_MODEL]]) ?? [] as $setting) {
                $this->stored[$setting->getName()] = $setting->getValue();
            }
        }

        return $this->stored;
    }
}
