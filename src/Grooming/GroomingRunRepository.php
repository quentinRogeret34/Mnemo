<?php

namespace App\Grooming;

use App\Entity\GroomingRun;
use App\Entity\Project;
use App\Entity\Proposal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistence queries for GroomingRun history and lookup.
 */
final readonly class GroomingRunRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<GroomingRun>
     */
    public function recentForProject(Project $project, int $limit = 30): array
    {
        /** @var list<GroomingRun> $runs */
        $runs = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(GroomingRun::class, 'r')
            ->where('r.project = :project')
            ->setParameter('project', $project)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $runs;
    }

    public function find(int $id): ?GroomingRun
    {
        return $this->entityManager->find(GroomingRun::class, $id);
    }

    public function findForProject(Project $project, int $id): ?GroomingRun
    {
        return $this->entityManager->getRepository(GroomingRun::class)->findOneBy([
            'id' => $id,
            'project' => $project,
        ]);
    }

    /**
     * @return list<Proposal>
     */
    public function findProposalsForRun(GroomingRun $run): array
    {
        $ids = $run->getCreatedProposalIds();
        if ([] === $ids) {
            return [];
        }

        /** @var list<Proposal> $proposals */
        $proposals = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Proposal::class, 'p')
            ->where('p.id IN (:ids)')
            ->andWhere('p.project = :project')
            ->setParameter('ids', $ids)
            ->setParameter('project', $run->getProject())
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $proposals;
    }
}
