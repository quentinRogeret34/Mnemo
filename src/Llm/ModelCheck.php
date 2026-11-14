<?php

namespace App\Llm;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\VectorResult;

/**
 * Tries a key and a model with one small call before they are saved: a mistyped model id would otherwise only
 * fail later, in the worker.
 */
final class ModelCheck
{
    public function __construct(
        private readonly LlmSettings $settings,
    ) {
    }

    /**
     * @return string|null what went wrong, null when the model answered
     */
    public function chat(string $model, #[\SensitiveParameter] ?string $key = null): ?string
    {
        try {
            $this->settings->platform($this->settings->chatPlatform, $key)->invoke($model, new MessageBag(Message::ofUser('Réponds uniquement par le mot OK.')), ['max_tokens' => 5])->getResult();
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @return string|null what went wrong, null when the model returned a vector
     */
    public function embedding(string $model, #[\SensitiveParameter] ?string $key = null): ?string
    {
        if (!\in_array($model, $this->settings->embeddingModels(), true)) {
            return 'modèle d\'embeddings inconnu, en choisir un dans la liste proposée.';
        }
        try {
            $result = $this->settings->platform($this->settings->embeddingPlatform, $key)->invoke($model, 'test')->getResult();
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        return $result instanceof VectorResult ? null : 'ce modèle ne renvoie pas de vecteurs.';
    }
}
