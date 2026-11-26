<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\SwarmMessage;
use App\Entity\SwarmRun;
use App\Enum\SwarmStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistence queries for Software Factory multi-agent swarm runs and shared board messages.
 */
final class SwarmRunRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPublicId(string $publicId): ?SwarmRun
    {
        return $this->entityManager->getRepository(SwarmRun::class)->findOneBy(['publicId' => trim($publicId)]);
    }

    /**
     * @param list<Project>|null $allowedProjects
     *
     * @return list<SwarmRun>
     */
    public function recent(
        ?Project $project = null,
        ?SwarmStatus $status = null,
        int $limit = 30,
        ?array $allowedProjects = null,
        bool $archived = false,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r', 'p')
            ->from(SwarmRun::class, 'r')
            ->innerJoin('r.project', 'p')
            ->andWhere($archived ? 'r.archivedAt IS NOT NULL' : 'r.archivedAt IS NULL')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)));

        if (null !== $project) {
            $qb->andWhere('r.project = :project')->setParameter('project', $project);
        } elseif (null !== $allowedProjects) {
            if ([] === $allowedProjects) {
                return [];
            }
            $qb->andWhere('r.project IN (:projects)')->setParameter('projects', $allowedProjects);
        }

        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        /** @var list<SwarmRun> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param list<Project>|null $allowedProjects
     */
    public function countArchived(?Project $project = null, ?array $allowedProjects = null): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(SwarmRun::class, 'r')
            ->andWhere('r.archivedAt IS NOT NULL');

        if (null !== $project) {
            $qb->andWhere('r.project = :project')->setParameter('project', $project);
        } elseif (null !== $allowedProjects) {
            if ([] === $allowedProjects) {
                return 0;
            }
            $qb->andWhere('r.project IN (:projects)')->setParameter('projects', $allowedProjects);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<SwarmMessage>
     */
    public function messagesFor(SwarmRun $swarm, int $limit = 200): array
    {
        /** @var list<SwarmMessage> $messages */
        $messages = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(SwarmMessage::class, 'm')
            ->where('m.swarm = :swarm')
            ->setParameter('swarm', $swarm)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();

        return $messages;
    }
}
