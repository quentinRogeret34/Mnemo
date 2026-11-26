<?php

namespace App\Repository;

use App\Entity\SwarmNode;
use App\Entity\SwarmRun;
use App\Enum\SwarmNodeStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistence queries for external client nodes connected to an interactive Swarm Hub.
 */
final class SwarmNodeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPublicId(string $publicId): ?SwarmNode
    {
        return $this->entityManager->getRepository(SwarmNode::class)->findOneBy(['publicId' => trim($publicId)]);
    }

    /**
     * @return list<SwarmNode>
     */
    public function forSwarm(SwarmRun $swarm): array
    {
        /** @var list<SwarmNode> $nodes */
        $nodes = $this->entityManager->createQueryBuilder()
            ->select('n', 's')
            ->from(SwarmNode::class, 'n')
            ->leftJoin('n.session', 's')
            ->where('n.swarm = :swarm')
            ->setParameter('swarm', $swarm)
            ->orderBy('n.connectedAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $nodes;
    }

    /**
     * Returns connected nodes whose effective presence status is not offline.
     *
     * @return list<SwarmNode>
     */
    public function activeForSwarm(SwarmRun $swarm): array
    {
        /** @var list<SwarmNode> $candidates */
        $candidates = $this->entityManager->createQueryBuilder()
            ->select('n', 's')
            ->from(SwarmNode::class, 'n')
            ->leftJoin('n.session', 's')
            ->where('n.swarm = :swarm')
            ->andWhere('n.status != :offline')
            ->andWhere('n.disconnectedAt IS NULL')
            ->setParameter('swarm', $swarm)
            ->setParameter('offline', SwarmNodeStatus::Offline)
            ->orderBy('n.connectedAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();

        $now = new \DateTimeImmutable();

        return array_values(array_filter(
            $candidates,
            static fn (SwarmNode $node): bool => $node->isOnline($now),
        ));
    }
}
