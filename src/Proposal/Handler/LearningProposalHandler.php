<?php

namespace App\Proposal\Handler;

use App\Entity\Node;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\MemoryVisibility;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class LearningProposalHandler implements ProposalHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MemoryStore $memoryStore,
    ) {
    }

    public function supports(ProposalKind $kind): bool
    {
        return ProposalKind::Learning === $kind;
    }

    public function apply(Proposal $proposal, array $input): ProposalApplyResult
    {
        $payload = $proposal->getPayload();
        $lesson = trim($input['lesson'] ?? (string) ($payload['lesson'] ?? ''));
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

            return new ProposalApplyResult(null, new TranslatableMessage('flash.proposal.applied'));
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

        $original = trim((string) ($payload['lesson'] ?? ''));
        $wasEdited = '' !== $original && $lesson !== $original;
        $proposal->annotatePayload([
            'human_feedback' => $wasEdited
                ? ['edited' => true, 'original_lesson' => $original, 'applied_lesson' => $lesson]
                : ['edited' => false],
        ]);

        return new ProposalApplyResult($memory, new TranslatableMessage('flash.proposal.applied_memory', ['memory' => $memory->getLabel()]));
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
