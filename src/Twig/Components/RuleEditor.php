<?php

namespace App\Twig\Components;

use App\Entity\Edge;
use App\Entity\Node;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use App\Rule\RuleStore;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Inline live editor for a BusinessRule: title, statement, implementing code files and source documents.
 */
#[AsLiveComponent]
final class RuleEditor
{
    use DefaultActionTrait;

    #[LiveProp]
    public Node $rule;

    #[LiveProp(writable: true)]
    public string $title = '';

    #[LiveProp(writable: true)]
    public string $statement = '';

    #[LiveProp(writable: true)]
    public string $codeQuery = '';

    #[LiveProp(writable: true)]
    public string $docQuery = '';

    public ?string $error = null;
    public bool $saved = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RuleStore $ruleStore,
        private readonly ProjectScope $projectScope,
    ) {
    }

    public function mount(Node $rule): void
    {
        $this->rule = $rule;
        $this->guard();
        $this->title = $rule->getLabel();
        $this->statement = (string) ($rule->getMetadata()['statement'] ?? '');
    }

    #[PostHydrate]
    public function guard(): void
    {
        $project = $this->rule->getProject();
        if (NodeType::BusinessRule !== $this->rule->getType() || null === $project || !$this->projectScope->allows($project)) {
            throw new NotFoundHttpException('Rule not found.');
        }
    }

    public function isDirty(): bool
    {
        return trim($this->title) !== $this->rule->getLabel()
            || trim($this->statement) !== trim((string) ($this->rule->getMetadata()['statement'] ?? ''));
    }

    /**
     * @return list<array{edge_id: int, id: int, path: string, origin: string, confidence: string}>
     */
    public function getImplementations(): array
    {
        return array_map(
            static fn (array $row): array => ['edge_id' => (int) $row['edge_id'], 'id' => (int) $row['id']] + $row,
            $this->entityManager->getConnection()->fetchAllAssociative(
                <<<'SQL'
                    SELECT e.id AS edge_id, f.id, f.path, e.origin, e.confidence
                    FROM edge e JOIN node f ON f.id = e.target_id
                    WHERE e.source_id = :rule AND e.relation = 'implemented_by' AND e.status = :active AND f.type = :file
                    ORDER BY f.path
                    SQL,
                ['rule' => $this->rule->getId(), 'active' => EdgeStatus::Active->value, 'file' => NodeType::CodeFile->value],
            ),
        );
    }

    /**
     * @return list<array{edge_id: int, id: int, title: string, format: string, origin: string, confidence: string}>
     */
    public function getSources(): array
    {
        return array_map(
            static fn (array $row): array => ['edge_id' => (int) $row['edge_id'], 'id' => (int) $row['id']] + $row,
            $this->entityManager->getConnection()->fetchAllAssociative(
                <<<'SQL'
                    SELECT e.id AS edge_id, d.id, d.label AS title, COALESCE(d.metadata->>'format', 'md') AS format, e.origin, e.confidence
                    FROM edge e JOIN node d ON d.id = e.source_id
                    WHERE e.target_id = :rule AND e.relation = 'defines' AND e.status = :active AND d.type = :document
                    ORDER BY d.label
                    SQL,
                ['rule' => $this->rule->getId(), 'active' => EdgeStatus::Active->value, 'document' => NodeType::Document->value],
            ),
        );
    }

    /**
     * @return list<array{id: int, path: string}>
     */
    public function getCodeCandidates(): array
    {
        $query = trim($this->codeQuery);
        if ('' === $query) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'path' => (string) $row['path']],
            $this->entityManager->getConnection()->fetchAllAssociative(
                <<<'SQL'
                    SELECT f.id, f.path FROM node f
                    WHERE f.project_id = :project AND f.type = :file AND f.path ILIKE :like
                      AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = :rule AND e.target_id = f.id AND e.relation = 'implemented_by' AND e.status = :active)
                    ORDER BY length(f.path), f.path LIMIT 8
                    SQL,
                [
                    'project' => $this->rule->getProject()?->getId(),
                    'file' => NodeType::CodeFile->value,
                    'rule' => $this->rule->getId(),
                    'active' => EdgeStatus::Active->value,
                    'like' => '%'.addcslashes($query, '\\%_').'%',
                ],
            ),
        );
    }

    /**
     * @return list<array{id: int, label: string, format: string}>
     */
    public function getDocumentCandidates(): array
    {
        $query = trim($this->docQuery);
        if ('' === $query) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label'], 'format' => (string) $row['format']],
            $this->entityManager->getConnection()->fetchAllAssociative(
                <<<'SQL'
                    SELECT d.id, d.label, COALESCE(d.metadata->>'format', 'md') AS format FROM node d
                    WHERE d.type = :document AND d.label ILIKE :like
                      AND (
                          EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id AND np.project_id = :project)
                          OR NOT EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id)
                      )
                      AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = d.id AND e.target_id = :rule AND e.relation = 'defines' AND e.status = :active)
                    ORDER BY d.label LIMIT 8
                    SQL,
                [
                    'document' => NodeType::Document->value,
                    'project' => $this->rule->getProject()?->getId(),
                    'rule' => $this->rule->getId(),
                    'active' => EdgeStatus::Active->value,
                    'like' => '%'.addcslashes($query, '\\%_').'%',
                ],
            ),
        );
    }

    #[LiveAction]
    public function save(): void
    {
        try {
            $this->ruleStore->update($this->rule, $this->title, $this->statement);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            $this->error = $exception->getMessage();

            return;
        }
        $this->title = $this->rule->getLabel();
        $this->statement = (string) ($this->rule->getMetadata()['statement'] ?? '');
        $this->error = null;
        $this->saved = true;
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->title = $this->rule->getLabel();
        $this->statement = (string) ($this->rule->getMetadata()['statement'] ?? '');
        $this->error = null;
        $this->saved = false;
    }

    #[LiveAction]
    public function linkCode(#[LiveArg] int $nodeId): void
    {
        $file = $this->entityManager->find(Node::class, $nodeId);
        if (null === $file || NodeType::CodeFile !== $file->getType() || $file->getProject() !== $this->rule->getProject()) {
            throw new \InvalidArgumentException('Unknown code file.');
        }
        $this->ruleStore->linkCode($this->rule, $file);
        $this->entityManager->flush();
        $this->codeQuery = '';
    }

    #[LiveAction]
    public function unlinkCode(#[LiveArg] int $edgeId): void
    {
        $edge = $this->entityManager->find(Edge::class, $edgeId);
        if (null === $edge || $edge->getSource() !== $this->rule) {
            throw new \InvalidArgumentException('Unknown edge.');
        }
        $this->ruleStore->unlinkCode($this->rule, $edgeId);
        $this->entityManager->flush();
    }

    #[LiveAction]
    public function linkDocument(#[LiveArg] int $nodeId): void
    {
        $document = $this->entityManager->find(Node::class, $nodeId);
        if (null === $document || NodeType::Document !== $document->getType() || !$this->projectScope->allowsNode($document)) {
            throw new \InvalidArgumentException('Unknown document.');
        }
        $this->ruleStore->linkDocument($this->rule, $document);
        $this->entityManager->flush();
        $this->docQuery = '';
    }

    #[LiveAction]
    public function unlinkDocument(#[LiveArg] int $edgeId): void
    {
        $edge = $this->entityManager->find(Edge::class, $edgeId);
        if (null === $edge || $edge->getTarget() !== $this->rule) {
            throw new \InvalidArgumentException('Unknown edge.');
        }
        $this->ruleStore->unlinkDocument($this->rule, $edgeId);
        $this->entityManager->flush();
    }
}
