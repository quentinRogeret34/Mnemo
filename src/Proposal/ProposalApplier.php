<?php

namespace App\Proposal;

use App\Entity\Node;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies or rejects what a human decided about a proposal. Flushes.
 */
final class ProposalApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemoryStore $memoryStore,
    ) {
    }

    /**
     * @param array{lesson?: string, keep?: int} $input the lesson as edited by the human, the memory kept from a pair
     *
     * @return Node|null the memory created or kept, if any
     */
    public function apply(Proposal $proposal, array $input = []): ?Node
    {
        if (!$proposal->isPending()) {
            throw new \InvalidArgumentException('Cette proposition a déjà été traitée.');
        }

        $payload = $proposal->getPayload();
        $memory = match ($proposal->getKind()) {
            ProposalKind::Learning => $this->applyLearning($proposal, $payload, trim($input['lesson'] ?? $payload['lesson'])),
            ProposalKind::Duplicate => $this->applyPair($payload, $input['keep'] ?? null, merge: true),
            ProposalKind::Contradiction => $this->applyPair($payload, $input['keep'] ?? null, merge: false),
            ProposalKind::Refuted => $this->applyRefuted($payload),
        };

        $proposal->decide(ProposalStatus::Applied);
        $this->entityManager->flush();

        return $memory;
    }

    public function reject(Proposal $proposal): void
    {
        if (!$proposal->isPending()) {
            throw new \InvalidArgumentException('Cette proposition a déjà été traitée.');
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
        $target = null === ($payload['target_id'] ?? null) ? null : $this->activeMemory($payload['target_id']);

        if ('retracts' === $payload['reconciliation']) {
            $this->memoryStore->archive($target, MemoryStore::STATUS_RETRACTED);

            return null;
        }

        if ('updates' === $payload['reconciliation']) {
            $memory = $target;
            $this->memoryStore->update($memory, $lesson, $type);
        } else {
            $memory = $this->memoryStore->findByText($lesson) ?? $this->memoryStore->add($lesson, $type, 'learn', [
                'score' => $payload['score'],
                'evidence' => $payload['evidence'],
                'learned_from' => $payload['source'],
            ]);
            if ('supersedes' === $payload['reconciliation']) {
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
