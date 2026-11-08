<?php

namespace App\Twig\Components;

use App\Entity\Project;
use App\Graph\KnowledgeReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Sidebar
{
    public ?Project $project = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KnowledgeReader $knowledgeReader,
    ) {
    }

    /**
     * @return list<Project>
     */
    public function getProjects(): array
    {
        return $this->entityManager->getRepository(Project::class)->findBy([], ['name' => 'ASC']);
    }

    public function getMemoryCount(): int
    {
        return $this->knowledgeReader->memoryCount();
    }

    /**
     * @return array{documents: int, memories: int, rules: int, rules_to_review: int, files: int}|null
     */
    public function getCounts(): ?array
    {
        return null === $this->project ? null : $this->knowledgeReader->counts($this->project);
    }
}
