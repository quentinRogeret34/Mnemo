<?php

namespace App\Proposal\Handler;

use App\Entity\Node;
use App\Entity\Proposal;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class RefutedProposalHandler implements ProposalHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MemoryStore $memoryStore,
    ) {
    }

    public function supports(ProposalKind $kind): bool
    {
        return ProposalKind::Refuted === $kind;
    }

    public function apply(Proposal $proposal, array $input): ProposalApplyResult
    {
        $id = (int) $proposal->getPayload()['memory_id'];
        $memory = $this->entityManager->getRepository(Node::class)->findOneBy(['id' => $id, 'type' => NodeType::Memory]);
        if (null === $memory || !MemoryStore::isActive($memory)) {
            throw new \InvalidArgumentException('La mémoire visée a été supprimée ou archivée entre-temps : rejeter la proposition.');
        }

        $this->memoryStore->archive($memory, MemoryStore::STATUS_RETRACTED);

        return new ProposalApplyResult(null, new TranslatableMessage('flash.proposal.applied'));
    }
}
