<?php

namespace App\Search;

use App\Llm\LlmSettings;
use Symfony\AI\Platform\Result\VectorResult;

class Embedder
{
    public function __construct(
        private readonly LlmSettings $settings,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->settings->isConfigured($this->settings->embeddingPlatform);
    }

    /**
     * Vectors of two models cannot be compared: the model is stored with each embedding.
     */
    public function model(): string
    {
        return $this->settings->embeddingPlatform.'/'.$this->settings->embeddingModel;
    }

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $result = $this->settings->platform($this->settings->embeddingPlatform)->invoke($this->settings->embeddingModel, $text)->getResult();
        if (!$result instanceof VectorResult) {
            throw new \UnexpectedValueException(\sprintf('Expected vectors, got %s.', $result::class));
        }

        return $result->getContent()[0]->getData();
    }
}
