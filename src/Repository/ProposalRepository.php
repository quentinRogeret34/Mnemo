<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Proposal\ProposalApplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Proposal>
 */
final class ProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Proposal::class);
    }

    /**
     * @param list<int>|null $onlyIds
     *
     * @return array<string, int>
     */
    public function countPendingByKind(?Project $project, ?array $onlyIds = null): array
    {
        $counts = [];
        foreach (ProposalKind::cases() as $case) {
            $counts[$case->value] = 0;
        }
        if ([] === $onlyIds) {
            return $counts;
        }

        $sql = 'SELECT kind, COUNT(*) FROM proposal WHERE status = :pending AND (CAST(:project AS int) IS NULL OR project_id = :project)';
        $params = ['pending' => ProposalStatus::Pending->value, 'project' => $project?->getId()];
        $types = [];
        if (null !== $onlyIds) {
            $sql .= ' AND id IN (:ids)';
            $params['ids'] = $onlyIds;
            $types['ids'] = ArrayParameterType::INTEGER;
        }
        $sql .= ' GROUP BY kind';

        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue($sql, $params, $types);
        foreach ($rows as $rowKind => $count) {
            $counts[(string) $rowKind] = (int) $count;
        }

        return $counts;
    }

    /**
     * @param list<int>|null $onlyIds
     *
     * @return list<Proposal>
     */
    public function findPending(?Project $project, ?ProposalKind $kind, int $limit, int $offset, ?array $onlyIds = null): array
    {
        if ([] === $onlyIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', ProposalStatus::Pending)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (null !== $project) {
            $qb->andWhere('p.project = :project')->setParameter('project', $project);
        }
        if (null !== $kind) {
            $qb->andWhere('p.kind = :kind')->setParameter('kind', $kind);
        }
        if (null !== $onlyIds) {
            $qb->andWhere('p.id IN (:ids)')->setParameter('ids', $onlyIds, ArrayParameterType::INTEGER);
        }

        /** @var list<Proposal> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    /**
     * @param list<int>|null $onlyIds
     */
    public function countPending(?Project $project, ?ProposalKind $kind = null, ?array $onlyIds = null): int
    {
        if ([] === $onlyIds) {
            return 0;
        }

        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :status')
            ->setParameter('status', ProposalStatus::Pending);

        if (null !== $project) {
            $qb->andWhere('p.project = :project')->setParameter('project', $project);
        }
        if (null !== $kind) {
            $qb->andWhere('p.kind = :kind')->setParameter('kind', $kind);
        }
        if (null !== $onlyIds) {
            $qb->andWhere('p.id IN (:ids)')->setParameter('ids', $onlyIds, ArrayParameterType::INTEGER);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param list<int> $projectIds
     *
     * @return array<int, int>
     */
    public function countPendingByProject(array $projectIds): array
    {
        if ([] === $projectIds) {
            return [];
        }
        $counts = array_fill_keys($projectIds, 0);
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT project_id, COUNT(*) AS total FROM proposal WHERE status = :pending AND project_id IN (:projects) GROUP BY project_id',
            ['pending' => ProposalStatus::Pending->value, 'projects' => $projectIds],
            ['projects' => ArrayParameterType::INTEGER],
        ) as $row) {
            $counts[(int) $row['project_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param list<int>|null $onlyIds
     */
    public function countPendingAutoApplicable(?Project $project, ?array $onlyIds = null): int
    {
        if ([] === $onlyIds) {
            return 0;
        }

        [$sql, $params, $types] = $this->autoApplicableSql('SELECT COUNT(*)', $project, $onlyIds);

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params, $types);
    }

    /**
     * @param list<int>|null $onlyIds
     *
     * @return list<Proposal>
     */
    public function findPendingAutoApplicable(?Project $project, ?array $onlyIds = null): array
    {
        if ([] === $onlyIds) {
            return [];
        }

        [$sql, $params, $types] = $this->autoApplicableSql('SELECT id', $project, $onlyIds);
        $ids = array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn($sql.' ORDER BY created_at ASC, id ASC', $params, $types));
        if ([] === $ids) {
            return [];
        }

        /** @var list<Proposal> $proposals */
        $proposals = $this->findBy(['id' => $ids], ['createdAt' => 'ASC', 'id' => 'ASC']);

        return $proposals;
    }

    /**
     * @param list<int>|null $onlyIds
     *
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private function autoApplicableSql(string $select, ?Project $project, ?array $onlyIds): array
    {
        $sql = $select." FROM proposal WHERE status = :pending AND kind = :kind AND jsonb_typeof(payload->'score') = 'number' AND (payload->>'score')::double precision > :threshold AND (CAST(:project AS int) IS NULL OR project_id = :project)";
        $params = [
            'pending' => ProposalStatus::Pending->value,
            'kind' => ProposalKind::Learning->value,
            'threshold' => ProposalApplier::AUTO_APPLY_SCORE_THRESHOLD,
            'project' => $project?->getId(),
        ];
        $types = [];
        if (null !== $onlyIds) {
            $sql .= ' AND id IN (:ids)';
            $params['ids'] = $onlyIds;
            $types['ids'] = ArrayParameterType::INTEGER;
        }

        return [$sql, $params, $types];
    }
}
