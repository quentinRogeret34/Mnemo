<?php

namespace App\Rule;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Creates, updates and links BusinessRule nodes with atomic RG-xxx code allocation.
 */
final class RuleStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function create(Project $project, string $title, string $statement, string $status = 'to_review', string $source = 'manual'): Node
    {
        $title = trim($title);
        $statement = trim($statement);
        if ('' === $title || mb_strlen($title) > 255 || '' === $statement || mb_strlen($statement) > 2000) {
            throw new \InvalidArgumentException('Le titre (255 caractères max) et l\'énoncé (2000 max) sont obligatoires.');
        }

        $lock = $this->lockFactory->createLock('rule-code-'.$project->getId(), 15);
        $lock->acquire(true);
        try {
            $last = (int) $this->entityManager->getConnection()->fetchOne(
                "SELECT COALESCE(MAX(substring(external_id FROM 4)::int), 0) FROM node WHERE project_id = :project AND type = :rule AND external_id ~ '^RG-[0-9]+$'",
                ['project' => $project->getId(), 'rule' => NodeType::BusinessRule->value],
            );
            $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
            $rule = new Node($project, NodeType::BusinessRule, \sprintf('RG-%03d', $last + 1), $title, null, null, [
                'statement' => $statement,
                'status' => \in_array($status, ['validated', 'to_review'], true) ? $status : 'to_review',
                'source' => $source,
                'history' => [['event' => 'ADD', 'title' => $title, 'statement' => $statement, 'at' => $now]],
            ]);
            $this->entityManager->persist($rule);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return $rule;
    }

    public function update(Node $rule, string $title, string $statement): void
    {
        $title = trim($title);
        $statement = trim($statement);
        if ('' === $title || mb_strlen($title) > 255 || '' === $statement || mb_strlen($statement) > 2000) {
            throw new \InvalidArgumentException('Le titre (255 caractères max) et l\'énoncé (2000 max) sont obligatoires.');
        }

        $metadata = $rule->getMetadata();
        $previousStatement = (string) ($metadata['statement'] ?? '');
        if ($title === $rule->getLabel() && $statement === $previousStatement) {
            return;
        }

        $history = \is_array($metadata['history'] ?? null) ? $metadata['history'] : [];
        $history[] = [
            'event' => 'UPDATE',
            'title' => $title,
            'statement' => $statement,
            'previous' => $previousStatement,
            'at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        $metadata['statement'] = $statement;
        $metadata['history'] = $history;
        $rule->update($title, $rule->getPath(), $rule->getLine(), $metadata);
    }

    public function linkCode(Node $rule, Node $file, Confidence $confidence = Confidence::Verified, EdgeOrigin $origin = EdgeOrigin::Human, ?string $evidence = 'lien manuel'): void
    {
        if (NodeType::CodeFile !== $file->getType() || $file->getProject() !== $rule->getProject()) {
            throw new \InvalidArgumentException('Only code files of the rule\'s project can be linked.');
        }

        $edge = $this->entityManager->getRepository(Edge::class)->findOneBy([
            'source' => $rule,
            'target' => $file,
            'relation' => 'implemented_by',
        ]);
        if (null !== $edge) {
            $edge->restore();

            return;
        }

        $this->entityManager->persist(new Edge($rule, $file, 'implemented_by', $confidence, $origin, $evidence));
    }

    public function unlinkCode(Node $rule, int $edgeId): void
    {
        $this->entityManager->getRepository(Edge::class)->findOneBy([
            'id' => $edgeId,
            'source' => $rule,
            'relation' => 'implemented_by',
        ])?->reject();
    }

    public function linkDocument(Node $rule, Node $document, Confidence $confidence = Confidence::Verified, EdgeOrigin $origin = EdgeOrigin::Human, ?string $evidence = 'lien manuel'): void
    {
        if (NodeType::Document !== $document->getType()) {
            throw new \InvalidArgumentException('Only documents can be linked as rule sources.');
        }

        $edge = $this->entityManager->getRepository(Edge::class)->findOneBy([
            'source' => $document,
            'target' => $rule,
            'relation' => 'defines',
        ]);
        if (null !== $edge) {
            $edge->restore();

            return;
        }

        $this->entityManager->persist(new Edge($document, $rule, 'defines', $confidence, $origin, $evidence));
    }

    public function unlinkDocument(Node $rule, int $edgeId): void
    {
        $this->entityManager->getRepository(Edge::class)->findOneBy([
            'id' => $edgeId,
            'target' => $rule,
            'relation' => 'defines',
        ])?->reject();
    }
}
