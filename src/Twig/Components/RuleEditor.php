<?php

namespace App\Twig\Components;

use App\Entity\Edge;
use App\Entity\Node;
use App\Enum\NodeType;
use App\Repository\RuleRepository;
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
        private readonly RuleRepository $ruleRepository,
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
        return $this->ruleRepository->implementations($this->rule);
    }

    /**
     * @return list<array{edge_id: int, id: int, title: string, format: string, origin: string, confidence: string}>
     */
    public function getSources(): array
    {
        return $this->ruleRepository->sources($this->rule);
    }

    /**
     * @return list<array{id: int, path: string}>
     */
    public function getCodeCandidates(): array
    {
        return $this->ruleRepository->codeCandidates($this->rule, $this->codeQuery);
    }

    /**
     * @return list<array{id: int, label: string, format: string}>
     */
    public function getDocumentCandidates(): array
    {
        return $this->ruleRepository->documentCandidates($this->rule, $this->docQuery);
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
