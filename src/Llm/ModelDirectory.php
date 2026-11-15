<?php

namespace App\Llm;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The models a model field offers: the OpenRouter catalogue for chat, the embedding models the bridge knows, and
 * the favourites of the instance, listed first.
 */
final class ModelDirectory
{
    public const FAVORITES = 'llm.favorite_models';
    private const CATALOGUE = 'https://openrouter.ai/api/v1/models';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly EntityManagerInterface $entityManager,
        private readonly LlmSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Chat models that call tools and answer in JSON, as the assistant, the extraction and the review need.
     * The catalogue is public and read once a day; when OpenRouter cannot be reached, the list is empty and the field
     * still takes any identifier.
     *
     * @return list<array{id: string, name: string, context: ?int, prompt: ?float, completion: ?float}>
     */
    public function chatModels(): array
    {
        try {
            return $this->cache->get('openrouter_chat_models', function (ItemInterface $item): array {
                $item->expiresAfter(86400);
                $models = [];
                foreach ($this->httpClient->request('GET', self::CATALOGUE, ['timeout' => 10])->toArray()['data'] ?? [] as $model) {
                    $parameters = $model['supported_parameters'] ?? [];
                    if (!\in_array('tools', $parameters, true) || [] === array_intersect(['response_format', 'structured_outputs'], $parameters)) {
                        continue;
                    }
                    $models[] = [
                        'id' => (string) $model['id'],
                        'name' => (string) ($model['name'] ?? $model['id']),
                        'context' => isset($model['context_length']) ? (int) $model['context_length'] : null,
                        // Prices come per token: shown per million.
                        'prompt' => isset($model['pricing']['prompt']) ? round((float) $model['pricing']['prompt'] * 1e6, 4) : null,
                        'completion' => isset($model['pricing']['completion']) ? round((float) $model['pricing']['completion'] * 1e6, 4) : null,
                    ];
                }
                usort($models, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

                return $models;
            });
        } catch (\Throwable $exception) {
            $this->logger->warning('OpenRouter model catalogue unavailable: {error}', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array{id: string, name: string, context: null, prompt: null, completion: null}>
     */
    public function embeddingModels(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id, 'name' => $id, 'context' => null, 'prompt' => null, 'completion' => null], $this->settings->embeddingModels());
    }

    /**
     * @return list<string>
     */
    public function favorites(): array
    {
        $setting = $this->entityManager->find(Setting::class, self::FAVORITES);
        $favorites = null === $setting ? [] : json_decode($setting->getValue(), true);

        return \is_array($favorites) ? array_values(array_filter($favorites, 'is_string')) : [];
    }
}
