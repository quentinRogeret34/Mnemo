<?php

namespace App\Memory;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\EdgeStatus;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Memories as graph nodes, mem0 style: short self-contained facts with a type, a source and an ADD/UPDATE history.
 * A memory replaced or withdrawn is archived (metadata status), never deleted, so that extraction does not recreate it.
 * Nothing is flushed here.
 */
final class MemoryStore
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_RETRACTED = 'retracted';
    public const ADR_FIELDS = ['context', 'decision', 'alternatives', 'consequences'];

    private const RELATION = 'about';
    private const SUPERSEDES = 'supersedes';
    private const LINKABLE = [NodeType::CodeFile, NodeType::Document, NodeType::BusinessRule];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function isActive(Node $memory): bool
    {
        return self::STATUS_ACTIVE === ($memory->getMetadata()['status'] ?? self::STATUS_ACTIVE);
    }

    /**
     * mem0 deduplicates with an LLM; here only exact duplicates (case-insensitive) among active memories are detected.
     */
    public function findByText(string $text): ?Node
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            "SELECT id FROM node WHERE type = :memory AND lower(label) = lower(:text) AND COALESCE(metadata->>'status', 'active') = 'active' ORDER BY id LIMIT 1",
            ['memory' => NodeType::Memory->value, 'text' => trim($text)],
        );

        return false === $id ? null : $this->entityManager->find(Node::class, $id);
    }

    public function countActive(Project $project): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM node m
                WHERE m.type = :memory AND COALESCE(m.metadata->>'status', 'active') = 'active'
                  AND EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project)
                SQL,
            ['memory' => NodeType::Memory->value, 'project' => $project->getId()],
        );
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

    /**
     * Decision record (context, decision, alternatives, consequences); empty fields are dropped, no field removes it.
     *
     * @param array<string, string> $adr
     */
    public function setAdr(Node $memory, array $adr): void
    {
        $adr = array_filter(array_map('trim', array_intersect_key($adr, array_flip(self::ADR_FIELDS))), 'strlen');
        $metadata = $memory->getMetadata();
        if ($adr === ($metadata['adr'] ?? [])) {
            return;
        }

        if ([] === $adr) {
            unset($metadata['adr']);
        } else {
            $metadata['adr'] = $adr;
        }
        $memory->update($memory->getLabel(), null, null, $metadata);
    }

    /**
     * The replacement, when given, is linked to the archived memory with a "supersedes" edge.
     */
    public function archive(Node $memory, string $status, ?Node $replacement = null): void
    {
        if (!\in_array($status, [self::STATUS_SUPERSEDED, self::STATUS_RETRACTED], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown memory status "%s".', $status));
        }

        if (null !== $replacement) {
            $edge = $this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $replacement, 'target' => $memory, 'relation' => self::SUPERSEDES]);
            null === $edge
                ? $this->entityManager->persist(new Edge($replacement, $memory, self::SUPERSEDES, Confidence::Verified, EdgeOrigin::Human))
                : $edge->restore();
        }

        if (!self::isActive($memory)) {
            return;
        }

        $metadata = $memory->getMetadata();
        $metadata['status'] = $status;
        $metadata['history'][] = ['event' => strtoupper($status), 'text' => $memory->getLabel(), 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM)]
            + (null === $replacement ? [] : ['replacement' => $replacement->getLabel()]);
        $memory->update($memory->getLabel(), null, null, $metadata);
    }

    /**
     * Keeps one memory of a duplicate pair: the other one's projects and active links move to it before it is archived.
     */
    public function merge(Node $kept, Node $dropped): void
    {
        foreach ($dropped->getProjects() as $project) {
            $kept->addProject($project);
        }

        $edges = $this->entityManager->getRepository(Edge::class)->findBy(['source' => $dropped, 'relation' => self::RELATION, 'status' => EdgeStatus::Active]);
        foreach ($edges as $edge) {
            $this->link($kept, $edge->getTarget(), $edge->getConfidence(), $edge->getOrigin(), $edge->getEvidence());
        }

        $this->archive($dropped, self::STATUS_SUPERSEDED, $kept);
    }

    /**
     * A link set by hand is verified; a link suggested by the LLM stays inferred until a human confirms it.
     * A rejected link is never recreated by an automatic source.
     */
    public function link(Node $memory, Node $target, Confidence $confidence = Confidence::Verified, EdgeOrigin $origin = EdgeOrigin::Human, ?string $evidence = 'lien manuel'): void
    {
        if (!\in_array($target->getType(), self::LINKABLE, true)) {
            throw new \InvalidArgumentException(\sprintf('A memory cannot be linked to a "%s" node.', $target->getType()->value));
        }
        if (null !== $target->getProject() && !$memory->getProjects()->contains($target->getProject())) {
            throw new \InvalidArgumentException('Code and rules can only be linked from a project the memory is attached to.');
        }

        $edge = null === $memory->getId() ? null : $this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $memory, 'target' => $target, 'relation' => self::RELATION]);
        if (null !== $edge) {
            if (EdgeOrigin::Human === $origin) {
                $edge->restore();
            }

            return;
        }

        $this->entityManager->persist(new Edge($memory, $target, self::RELATION, $confidence, $origin, $evidence));
    }

    /**
     * Links a memory to code files (by path) and business rules (by code) of the given projects.
     *
     * @param list<Project> $projects
     * @param list<string>  $files
     * @param list<string>  $rules
     *
     * @return list<string> the paths and codes found in none of the projects
     */
    public function linkReferences(Node $memory, array $projects, array $files, array $rules, Confidence $confidence = Confidence::Verified, EdgeOrigin $origin = EdgeOrigin::Human, ?string $evidence = 'lien manuel'): array
    {
        $notFound = [];
        $nodes = $this->entityManager->getRepository(Node::class);
        foreach ([NodeType::CodeFile->value => $files, NodeType::BusinessRule->value => $rules] as $type => $references) {
            foreach (array_unique($references) as $reference) {
                $criteria = NodeType::CodeFile->value === $type ? ['path' => $reference] : ['externalId' => $reference];
                $target = [] === $projects ? null : $nodes->findOneBy(['project' => $projects, 'type' => NodeType::from($type)] + $criteria);
                null === $target ? $notFound[] = $reference : $this->link($memory, $target, $confidence, $origin, $evidence);
            }
        }

        return $notFound;
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
