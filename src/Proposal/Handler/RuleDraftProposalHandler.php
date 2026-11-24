<?php

namespace App\Proposal\Handler;

use App\Entity\Node;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Rule\RuleStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class RuleDraftProposalHandler implements ProposalHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RuleStore $ruleStore,
    ) {
    }

    public function supports(ProposalKind $kind): bool
    {
        return ProposalKind::RuleDraft === $kind;
    }

    public function apply(Proposal $proposal, array $input): ProposalApplyResult
    {
        $project = $proposal->getProject();
        if (null === $project) {
            throw new \InvalidArgumentException('Cette proposition de règle n\'est rattachée à aucun projet.');
        }

        $payload = $proposal->getPayload();
        $originalTitle = trim((string) ($payload['title'] ?? ''));
        $originalStatement = trim((string) ($payload['statement'] ?? ''));
        $title = trim($input['title'] ?? $originalTitle);
        $statement = trim($input['statement'] ?? $originalStatement);

        $rule = $this->ruleStore->create($project, $title, $statement, status: 'validated', source: 'document');

        $documentId = isset($payload['document_id']) ? (int) $payload['document_id'] : 0;
        if ($documentId > 0) {
            $document = $this->entityManager->find(Node::class, $documentId);
            if (null !== $document && NodeType::Document === $document->getType()) {
                $this->ruleStore->linkDocument($rule, $document, Confidence::Verified, EdgeOrigin::Human, 'extrait du document');
            }
        }

        $files = \is_array($payload['files'] ?? null) ? \array_slice($payload['files'], 0, 5) : [];
        foreach ($files as $filePath) {
            if (!\is_string($filePath) || '' === trim($filePath)) {
                continue;
            }
            $file = $this->entityManager->getRepository(Node::class)->findOneBy([
                'project' => $project,
                'type' => NodeType::CodeFile,
                'path' => $filePath,
            ]);
            if (null !== $file) {
                $this->ruleStore->linkCode($rule, $file, Confidence::Inferred, EdgeOrigin::Llm, 'suggéré lors de l\'extraction');
            }
        }

        $wasEdited = $title !== $originalTitle || $statement !== $originalStatement;
        $proposal->annotatePayload([
            'human_feedback' => $wasEdited
                ? ['edited' => true, 'applied_title' => $title, 'applied_statement' => $statement]
                : ['edited' => false],
            'applied_rule_code' => $rule->getExternalId(),
        ]);

        return new ProposalApplyResult($rule, new TranslatableMessage('flash.proposal.applied_rule', [
            'code' => $rule->getExternalId(),
            'title' => $rule->getLabel(),
        ]));
    }
}
