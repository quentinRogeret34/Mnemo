<?php

namespace App\Memory;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Memories as graph nodes, mem0 style: short self-contained facts with a type, a source and an ADD/UPDATE history.
 * Nothing is flushed here.
 */
final class MemoryStore
{
    private const RELATION = 'about';
    private const LINKABLE = [NodeType::CodeFile, NodeType::Document, NodeType::BusinessRule];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * mem0 deduplicates with an LLM; until extraction exists, only exact duplicates (case-insensitive) are detected.
     */
    public function findByText(string $text): ?Node
    {
        return $this->entityManager->createQuery('SELECT m FROM App\Entity\Node m WHERE m.type = :memory AND LOWER(m.label) = LOWER(:text)')
            ->setParameters(['memory' => NodeType::Memory, 'text' => trim($text)])
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function add(string $text, MemoryType $type, string $source, array $metadata = []): Node
    {
        $text = trim($text);
        $memory = new Node(null, NodeType::Memory, Uuid::v7()->toRfc4122(), $text, null, null, [
            'type' => $type->value,
            'source' => $source,
            'history' => [['event' => 'ADD', 'text' => $text, 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM)]],
        ] + $metadata);
        $this->entityManager->persist($memory);

        return $memory;
    }

    public function update(Node $memory, string $text, MemoryType $type): void
    {
        $text = trim($text);
        $metadata = $memory->getMetadata();
        if ($text === $memory->getLabel() && $type->value === $metadata['type']) {
            return;
        }

        $metadata['type'] = $type->value;
        $metadata['history'][] = ['event' => 'UPDATE', 'text' => $text, 'previous' => $memory->getLabel(), 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM)];
        $memory->update($text, null, null, $metadata);
    }

    public function link(Node $memory, Node $target): void
    {
        if (!\in_array($target->getType(), self::LINKABLE, true)) {
            throw new \InvalidArgumentException(\sprintf('A memory cannot be linked to a "%s" node.', $target->getType()->value));
        }
        if (null !== $target->getProject() && !$memory->getProjects()->contains($target->getProject())) {
            throw new \InvalidArgumentException('Code and rules can only be linked from a project the memory is attached to.');
        }

        $edge = $this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $memory, 'target' => $target, 'relation' => self::RELATION]);
        if (null !== $edge) {
            $edge->restore();

            return;
        }

        $this->entityManager->persist(new Edge($memory, $target, self::RELATION, Confidence::Verified, EdgeOrigin::Human, 'lien manuel'));
    }

    /**
     * Links to the project's code and rules are rejected too: they can only be linked from a project the memory is attached to.
     */
    public function detachProject(Node $memory, Project $project): void
    {
        $memory->removeProject($project);
        $edges = $this->entityManager->createQuery('SELECT e FROM App\Entity\Edge e JOIN e.target t WHERE e.source = :memory AND e.relation = :relation AND t.project = :project')
            ->setParameters(['memory' => $memory, 'relation' => self::RELATION, 'project' => $project])
            ->getResult();
        foreach ($edges as $edge) {
            $edge->reject();
        }
    }

    /**
     * Kept as rejected so that automatic extraction does not recreate the link.
     */
    public function unlink(Node $memory, int $edgeId): void
    {
        $this->entityManager->getRepository(Edge::class)->findOneBy(['id' => $edgeId, 'source' => $memory, 'relation' => self::RELATION])?->reject();
    }
}
