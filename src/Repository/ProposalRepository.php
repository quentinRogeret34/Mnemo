<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
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
     * @return array<string, int>
     */
    public function countPendingByKind(?Project $project): array
    {
        $counts = [];
        foreach (ProposalKind::cases() as $case) {
            $counts[$case->value] = 0;
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT kind, COUNT(*) FROM proposal WHERE status = :pending AND (CAST(:project AS int) IS NULL OR project_id = :project) GROUP BY kind',
            ['pending' => ProposalStatus::Pending->value, 'project' => $project?->getId()],
        );
        foreach ($rows as $rowKind => $count) {
            $counts[(string) $rowKind] = (int) $count;
        }

        return $counts;
    }

    /**
     * @return list<Proposal>
     */
    public function findPending(?Project $project, ?ProposalKind $kind, int $limit, int $offset): array
    {
        $criteria = $this->pendingCriteria($project, $kind);

        return $this->findBy($criteria, ['createdAt' => 'ASC', 'id' => 'ASC'], $limit, $offset);
    }

    public function countPending(?Project $project, ?ProposalKind $kind = null): int
    {
        return $this->count($this->pendingCriteria($project, $kind));
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
     * @return array<string, mixed>
     */
    private function pendingCriteria(?Project $project, ?ProposalKind $kind): array
    {
        return ['status' => ProposalStatus::Pending]
            + (null === $project ? [] : ['project' => $project])
            + (null === $kind ? [] : ['kind' => $kind]);
    }
}
