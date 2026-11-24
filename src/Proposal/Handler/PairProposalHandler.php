<?php

namespace App\Proposal\Handler;

use App\Entity\Node;
use App\Entity\Proposal;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PairProposalHandler implements ProposalHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MemoryStore $memoryStore,
    ) {
    }

    public function supports(ProposalKind $kind): bool
    {
        return \in_array($kind, [ProposalKind::Duplicate, ProposalKind::Contradiction], true);
    }

    public function apply(Proposal $proposal, array $input): ProposalApplyResult
    {
        $payload = $proposal->getPayload();
        $keep = $input['keep'] ?? null;
        if (!\in_array($keep, [$payload['a_id'], $payload['b_id']], true)) {
            throw new \InvalidArgumentException('Choisir la mémoire à garder.');
        }

        $kept = $this->activeMemory((int) $keep);
        $dropped = $this->activeMemory((int) ($keep === $payload['a_id'] ? $payload['b_id'] : $payload['a_id']));

        ProposalKind::Duplicate === $proposal->getKind()
            ? $this->memoryStore->merge($kept, $dropped)
            : $this->memoryStore->archive($dropped, MemoryStore::STATUS_SUPERSEDED, $kept);

        return new ProposalApplyResult($kept, new TranslatableMessage('flash.proposal.applied_memory', ['memory' => $kept->getLabel()]));
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
