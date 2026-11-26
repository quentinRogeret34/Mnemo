<?php

namespace App\Repository;

use App\Entity\AgentSession;
use App\Entity\AgentSessionEvent;
use App\Entity\Project;
use App\Enum\AgentSessionStatus;
use App\Factory\Dto\FactoryStats;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistence queries for the Software Factory agent sessions and timeline events.
 */
final class AgentSessionRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
    }

    public function findByPublicId(string $publicId): ?AgentSession
    {
        return $this->entityManager->getRepository(AgentSession::class)->findOneBy(['publicId' => trim($publicId)]);
    }

    /**
     * @param list<Project>|null $allowedProjects null means unrestricted (all projects)
     *
     * @return list<AgentSession>
     */
    public function recent(
        ?Project $project = null,
        ?AgentSessionStatus $status = null,
        int $limit = 50,
        ?array $allowedProjects = null,
        bool $archived = false,
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('s', 'p')
            ->from(AgentSession::class, 's')
            ->innerJoin('s.project', 'p')
            ->andWhere($archived ? 's.archivedAt IS NOT NULL' : 's.archivedAt IS NULL')
            ->orderBy('s.startedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)));

        if (null !== $project) {
            $qb->andWhere('s.project = :project')->setParameter('project', $project);
        } elseif (null !== $allowedProjects) {
            if ([] === $allowedProjects) {
                return [];
            }
            $qb->andWhere('s.project IN (:projects)')->setParameter('projects', $allowedProjects);
        }

        if (null !== $status) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }

        /** @var list<AgentSession> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param list<Project>|null $allowedProjects
     */
    public function countArchived(?Project $project = null, ?array $allowedProjects = null): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(AgentSession::class, 's')
            ->andWhere('s.archivedAt IS NOT NULL');

        if (null !== $project) {
            $qb->andWhere('s.project = :project')->setParameter('project', $project);
        } elseif (null !== $allowedProjects) {
            if ([] === $allowedProjects) {
                return 0;
            }
            $qb->andWhere('s.project IN (:projects)')->setParameter('projects', $allowedProjects);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<AgentSessionEvent>
     */
    public function eventsFor(AgentSession $session, int $limit = 200): array
    {
        /** @var list<AgentSessionEvent> $events */
        $events = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(AgentSessionEvent::class, 'e')
            ->where('e.session = :session')
            ->setParameter('session', $session)
            ->orderBy('e.createdAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();

        return $events;
    }

    /**
     * @return list<AgentSession>
     */
    public function childrenOf(AgentSession $session): array
    {
        /** @var list<AgentSession> $children */
        $children = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(AgentSession::class, 's')
            ->where('s.parentSession = :parent')
            ->setParameter('parent', $session)
            ->orderBy('s.startedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $children;
    }

    /**
     * @param list<Project>|null $allowedProjects
     */
    public function summaryStats(?Project $project = null, ?array $allowedProjects = null): FactoryStats
    {
        $where = ['archived_at IS NULL'];
        $params = [
            'since' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s'),
        ];
        $types = [];

        if (null !== $project) {
            $where[] = 'project_id = :projectId';
            $params['projectId'] = $project->getId();
        } elseif (null !== $allowedProjects) {
            $ids = array_values(array_filter(array_map(static fn (Project $p): ?int => $p->getId(), $allowedProjects), static fn (?int $id): bool => null !== $id));
            if ([] === $ids) {
                return new FactoryStats(0, 0, 0, 0, 0);
            }
            $where[] = 'project_id IN (:projectIds)';
            $params['projectIds'] = $ids;
            $types['projectIds'] = ArrayParameterType::INTEGER;
        }

        $sql = <<<'SQL'
            SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE status IN ('queued', 'running', 'waiting_human'))::int AS active,
                COUNT(*) FILTER (WHERE status = 'succeeded')::int AS succeeded,
                COUNT(*) FILTER (WHERE status = 'failed')::int AS failed,
                COUNT(*) FILTER (WHERE started_at >= :since)::int AS last_24h
            FROM agent_session
        SQL;
        if ([] !== $where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $row = $this->connection->fetchAssociative($sql, $params, $types);
        if (!\is_array($row)) {
            return new FactoryStats(0, 0, 0, 0, 0);
        }

        return new FactoryStats(
            total: \is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0,
            active: \is_numeric($row['active'] ?? null) ? (int) $row['active'] : 0,
            succeeded: \is_numeric($row['succeeded'] ?? null) ? (int) $row['succeeded'] : 0,
            failed: \is_numeric($row['failed'] ?? null) ? (int) $row['failed'] : 0,
            last24h: \is_numeric($row['last_24h'] ?? null) ? (int) $row['last_24h'] : 0,
        );
    }
}
