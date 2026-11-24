<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function countPending(?Project $project, ?ProposalKind $kind): int
    {
        return $this->count($this->pendingCriteria($project, $kind));
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
