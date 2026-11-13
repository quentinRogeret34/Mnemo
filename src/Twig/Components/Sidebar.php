<?php

namespace App\Twig\Components;

use App\Entity\Project;
use App\Graph\KnowledgeReader;
use App\Project\ProjectResolver;
use App\Security\ProjectScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Sidebar
{
    public ?Project $project = null;

    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly ProjectScope $projectScope,
        private readonly ProjectResolver $projectResolver,
    ) {
    }

    public function mayCreate(): bool
    {
        return $this->projectResolver->mayCreate();
    }

    /**
     * @return list<Project>
     */
    public function getProjects(): array
    {
        return $this->projectScope->projects();
    }

    /**
     * A caller limited to some projects has no view across all of them: the workspace entries are hidden.
     */
    public function isScoped(): bool
    {
        return null !== $this->projectScope->slugs();
    }

    public function getMemoryCount(): int
    {
        return $this->knowledgeReader->memoryCount();
    }

    public function getPendingProposals(): int
    {
        return $this->knowledgeReader->pendingProposals();
    }

    /**
     * @return array{documents: int, memories: int, rules: int, rules_to_review: int, files: int}|null
     */
    public function getCounts(): ?array
    {
        return null === $this->project ? null : $this->knowledgeReader->counts($this->project);
    }
}
