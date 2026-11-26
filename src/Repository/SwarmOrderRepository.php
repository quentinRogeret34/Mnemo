<?php

namespace App\Repository;

use App\Entity\SwarmOrder;
use App\Entity\SwarmRun;
use App\Enum\SwarmOrderStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistence queries for work orders dispatched through an interactive Swarm Hub.
 */
final class SwarmOrderRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPublicId(string $publicId): ?SwarmOrder
    {
        return $this->entityManager->getRepository(SwarmOrder::class)->findOneBy(['publicId' => trim($publicId)]);
    }

    /**
     * @return list<SwarmOrder>
     */
    public function forSwarm(SwarmRun $swarm): array
    {
        /** @var list<SwarmOrder> $orders */
        $orders = $this->entityManager->createQueryBuilder()
            ->select('o', 'tn', 'cn')
            ->from(SwarmOrder::class, 'o')
            ->leftJoin('o.targetNode', 'tn')
            ->leftJoin('o.claimedByNode', 'cn')
            ->where('o.swarm = :swarm')
            ->setParameter('swarm', $swarm)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $orders;
    }

    /**
     * @return list<SwarmOrder>
     */
    public function pendingForSwarm(SwarmRun $swarm): array
    {
        /** @var list<SwarmOrder> $orders */
        $orders = $this->entityManager->createQueryBuilder()
            ->select('o', 'tn')
            ->from(SwarmOrder::class, 'o')
            ->leftJoin('o.targetNode', 'tn')
            ->where('o.swarm = :swarm')
            ->andWhere('o.status = :pending')
            ->setParameter('swarm', $swarm)
            ->setParameter('pending', SwarmOrderStatus::Pending)
            ->orderBy('o.createdAt', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $orders;
    }
}
