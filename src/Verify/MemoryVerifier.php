<?php

namespace App\Verify;

use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Memory\MemoryStore;
use App\Security\MemoryAccess;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks memories against the graph. The verdict is stored on the memory with the text it was given, so that an edit
 * makes it stale; a refuted or conflicting memory is proposed for withdrawal, never withdrawn here.
 */
final class MemoryVerifier
{
    public function __construct(
        private readonly FactChecker $factChecker,
        private readonly EntityManagerInterface $entityManager,
        private readonly MemoryAccess $memoryAccess,
    ) {
    }

    /**
     * @return array{verdict: string, tier: string, sources: list<array{id: int, type: string, label: string}>, reason: string}
     */
    public function verify(Node $memory, Project $project): array
    {
        $linked = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT target_id FROM edge WHERE source_id = :memory AND relation = 'about' AND status = :active",
            ['memory' => $memory->getId(), 'active' => EdgeStatus::Active->value],
        );
        $verification = $this->factChecker->check($memory->getLabel(), $project, array_map('intval', $linked));

        $memory->annotate(['verification' => $verification + ['text' => $memory->getLabel(), 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM)]] + $memory->getMetadata());
        if (\in_array($verification['verdict'], ['refuted', 'conflict'], true) && MemoryStore::isActive($memory) && !$this->hasPendingProposal($memory)) {
            $this->entityManager->persist(new Proposal($project, ProposalKind::Refuted, [
                'memory_id' => $memory->getId(),
                'memory_text' => $memory->getLabel(),
                'verdict' => $verification['verdict'],
                'reason' => $verification['reason'],
                'sources' => $verification['sources'],
            ]));
        }
        $this->entityManager->flush();

        return $verification;
    }

    /**
     * Active memories of the project never verified, or edited since.
     *
     * @return array{checked: int, verified: int, refuted: int, conflict: int, unverified: int}
     */
    public function verifyProject(Project $project, int $limit): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            <<<SQL
                SELECT m.id FROM node m
                WHERE m.type = :memory AND COALESCE(m.metadata->>'status', 'active') = 'active' AND {$this->memoryAccess->sql('m')}
                  AND EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project)
                  AND (m.metadata->'verification' IS NULL OR m.metadata->'verification'->>'text' IS DISTINCT FROM m.label)
                ORDER BY m.id
                LIMIT :limit
                SQL,
            ['memory' => NodeType::Memory->value, 'project' => $project->getId(), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        $report = ['checked' => 0, 'verified' => 0, 'refuted' => 0, 'conflict' => 0, 'unverified' => 0];
        foreach ($this->entityManager->getRepository(Node::class)->findBy(['id' => $ids], ['id' => 'ASC']) as $memory) {
            ++$report['checked'];
            ++$report[$this->verify($memory, $project)['verdict']];
        }

        return $report;
    }

    private function hasPendingProposal(Node $memory): bool
    {
        foreach ($this->entityManager->getRepository(Proposal::class)->findBy(['kind' => ProposalKind::Refuted, 'status' => ProposalStatus::Pending]) as $proposal) {
            if ($memory->getId() === $proposal->getPayload()['memory_id']) {
                return true;
            }
        }

        return false;
    }
}
