<?php

namespace App\Search;

use App\Entity\Project;
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
     * A project may name its own model; without a project, the model of the instance.
     */
    public function model(?Project $project = null): string
    {
        return $this->settings->embeddingPlatform.'/'.$this->settings->embeddingModel($project);
    }

    /**
     * @return list<float>
     */
    public function embed(string $text, ?Project $project = null): array
    {
        $result = $this->settings->platform($this->settings->embeddingPlatform)->invoke($this->settings->embeddingModel($project), $text)->getResult();
        if (!$result instanceof VectorResult) {
            throw new \UnexpectedValueException(\sprintf('Expected vectors, got %s.', $result::class));
        }

        return $result->getContent()[0]->getData();
    }
}
