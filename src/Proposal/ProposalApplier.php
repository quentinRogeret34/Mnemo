<?php

namespace App\Proposal;

use App\Entity\AgentAsset;
use App\Entity\Node;
use App\Entity\Proposal;
use App\Enum\AgentAssetKind;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\MemoryVisibility;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Memory\MemoryStore;
use App\Rule\RuleStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies or rejects what a human decided about a proposal. Flushes.
 */
final class ProposalApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemoryStore $memoryStore,
        private readonly RuleStore $ruleStore,
    ) {
    }

    /**
     * @param array{lesson?: string, keep?: int, title?: string, statement?: string, name?: string, description?: string, content?: string} $input the edited text/rule/skill fields or the memory kept from a pair
     *
     * @return Node|null the memory or business rule created or kept, if any
     */
    public function apply(Proposal $proposal, array $input = []): ?Node
    {
        if (!$proposal->isPending()) {
            throw new \InvalidArgumentException('Cette proposition a déjà été traitée.');
        }

        $payload = $proposal->getPayload();
        $editedLesson = ProposalKind::Learning === $proposal->getKind() ? trim($input['lesson'] ?? (string) ($payload['lesson'] ?? '')) : null;
        $result = match ($proposal->getKind()) {
            ProposalKind::Learning => $this->applyLearning($proposal, $payload, (string) $editedLesson),
            ProposalKind::RuleDraft => $this->applyRuleDraft($proposal, $payload, $input),
            ProposalKind::SkillUpdate => $this->applySkillUpdate($proposal, $payload, $input),
            ProposalKind::Duplicate => $this->applyPair($payload, $input['keep'] ?? null, merge: true),
            ProposalKind::Contradiction => $this->applyPair($payload, $input['keep'] ?? null, merge: false),
            ProposalKind::Refuted => $this->applyRefuted($payload),
        };

        if (ProposalKind::Learning === $proposal->getKind() && 'retracts' !== ($payload['reconciliation'] ?? 'new')) {
            $original = trim((string) ($payload['lesson'] ?? ''));
            $wasEdited = '' !== $original && $editedLesson !== $original;
            $proposal->annotatePayload([
                'human_feedback' => $wasEdited
                    ? ['edited' => true, 'original_lesson' => $original, 'applied_lesson' => $editedLesson]
                    : ['edited' => false],
            ]);
        }

        $proposal->decide(ProposalStatus::Applied);
        $this->entityManager->flush();

        return $result;
    }

    public function reject(Proposal $proposal, ?string $reason = null): void
    {
        if (!$proposal->isPending()) {
            throw new \InvalidArgumentException('Cette proposition a déjà été traitée.');
        }

        $reason = null === $reason ? null : mb_substr(trim($reason), 0, 120);
        if (null !== $reason && '' !== $reason) {
            $proposal->annotatePayload(['human_feedback' => ['rejected_reason' => $reason]]);
        }

        $proposal->decide(ProposalStatus::Rejected);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyLearning(Proposal $proposal, array $payload, string $lesson): ?Node
    {
        if ('' === $lesson || mb_strlen($lesson) > 1000) {
            throw new \InvalidArgumentException('La mémoire doit contenir entre 1 et 1000 caractères.');
        }
        $type = MemoryType::from($payload['type']);
        $reconciliation = (string) ($payload['reconciliation'] ?? 'new');
        $target = null === ($payload['target_id'] ?? null) ? null : $this->activeMemory((int) $payload['target_id']);
        if (\in_array($reconciliation, ['retracts', 'updates', 'supersedes'], true) && null === $target) {
            throw new \InvalidArgumentException('La mémoire visée est absente de la proposition : rejeter la proposition.');
        }

        if ('retracts' === $reconciliation) {
            $this->memoryStore->archive($target, MemoryStore::STATUS_RETRACTED);

            return null;
        }

        if ('updates' === $reconciliation) {
            $memory = $target;
            $this->memoryStore->update($memory, $lesson, $type);
        } else {
            $projects = null === $proposal->getProject() ? [] : [$proposal->getProject()];
            $memory = $this->memoryStore->findByText($lesson, $projects, MemoryVisibility::Project) ?? $this->memoryStore->add($lesson, $type, 'learn', [
                'score' => $payload['score'],
                'evidence' => $payload['evidence'],
                'learned_from' => $payload['source'],
            ]);
            if ('supersedes' === $reconciliation) {
                $this->memoryStore->archive($target, MemoryStore::STATUS_SUPERSEDED, $memory);
            }
        }

        if (MemoryType::Decision === $type && [] !== ($payload['adr'] ?? [])) {
            $this->memoryStore->setAdr($memory, $payload['adr']);
        }
        if (null !== $proposal->getProject()) {
            $memory->addProject($proposal->getProject());
            $this->memoryStore->linkReferences($memory, [$proposal->getProject()], $payload['files'] ?? [], $payload['rules'] ?? [], Confidence::Inferred, EdgeOrigin::Llm, $payload['evidence']);
        }

        return $memory;
    }

    /**
     * @param array<string, mixed>                                                   $payload
     * @param array{lesson?: string, keep?: int, title?: string, statement?: string} $input
     */
    private function applyRuleDraft(Proposal $proposal, array $payload, array $input): Node
    {
        $project = $proposal->getProject();
        if (null === $project) {
            throw new \InvalidArgumentException('Cette proposition de règle n\'est rattachée à aucun projet.');
        }

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

        return $rule;
    }

    /**
     * @param array<string, mixed>                                                                                                          $payload
     * @param array{lesson?: string, keep?: int, title?: string, statement?: string, name?: string, description?: string, content?: string} $input
     */
    private function applySkillUpdate(Proposal $proposal, array $payload, array $input): null
    {
        $project = $proposal->getProject();
        if (null === $project) {
            throw new \InvalidArgumentException('Cette proposition de skill n\'est rattachée à aucun projet.');
        }

        $kind = AgentAssetKind::tryFrom((string) ($payload['kind'] ?? 'skill')) ?? AgentAssetKind::Skill;
        $originalName = trim((string) ($payload['name'] ?? ''));
        $originalDescription = trim((string) ($payload['description'] ?? ''));
        $originalContent = trim((string) ($payload['content'] ?? ''));

        $name = trim($input['name'] ?? $originalName);
        $description = trim($input['description'] ?? $originalDescription);
        $content = trim($input['content'] ?? $originalContent);

        if ('' === $name || mb_strlen($name) > 64 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            throw new \InvalidArgumentException('Le nom du skill doit être en minuscules, chiffres et tirets (64 caractères max).');
        }
        if ('' === $description || mb_strlen($description) > 500 || '' === $content || mb_strlen($content) > 100000) {
            throw new \InvalidArgumentException('La description (500 max) et les instructions sont obligatoires.');
        }

        $repository = $this->entityManager->getRepository(AgentAsset::class);
        $asset = null;
        if (isset($payload['asset_id']) && (int) $payload['asset_id'] > 0) {
            $asset = $repository->findOneBy(['id' => (int) $payload['asset_id'], 'project' => $project]);
        }
        $asset ??= $repository->findOneBy(['project' => $project, 'kind' => $kind, 'name' => $name]);
        if (null === $asset) {
            $asset = new AgentAsset($project);
            $asset->setKind($kind);
            $this->entityManager->persist($asset);
        }

        $asset->setName($name);
        $asset->setDescription($description);
        $asset->setContent($content);

        $wasEdited = $name !== $originalName || $description !== $originalDescription || $content !== $originalContent;
        $proposal->annotatePayload([
            'human_feedback' => $wasEdited
                ? ['edited' => true, 'applied_name' => $name]
                : ['edited' => false],
            'applied_asset_name' => $name,
        ]);

        return null;
    }

    /**
     * @param array{a_id: int, b_id: int} $payload
     */
    private function applyPair(array $payload, ?int $keep, bool $merge): Node
    {
        if (!\in_array($keep, [$payload['a_id'], $payload['b_id']], true)) {
            throw new \InvalidArgumentException('Choisir la mémoire à garder.');
        }
        $kept = $this->activeMemory($keep);
        $dropped = $this->activeMemory($keep === $payload['a_id'] ? $payload['b_id'] : $payload['a_id']);

        $merge
            ? $this->memoryStore->merge($kept, $dropped)
            : $this->memoryStore->archive($dropped, MemoryStore::STATUS_SUPERSEDED, $kept);

        return $kept;
    }

    /**
     * @param array{memory_id: int} $payload
     */
    private function applyRefuted(array $payload): null
    {
        $this->memoryStore->archive($this->activeMemory($payload['memory_id']), MemoryStore::STATUS_RETRACTED);

        return null;
    }

    private function activeMemory(int $id): Node
    {
        $memory = $this->entityManager->getRepository(Node::class)->findOneBy(['id' => $id, 'type' => NodeType::Memory]);
        if (null === $memory || !MemoryStore::isActive($memory)) {
            throw new \InvalidArgumentException('La mémoire visée a été supprimée ou archivée entre-temps : rejeter la proposition.');
        }

        return $memory;
    }
}
