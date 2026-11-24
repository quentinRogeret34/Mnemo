<?php

namespace App\MessageHandler;

use App\Entity\Project;
use App\Enum\NodeType;
use App\Garden\DuplicateFinder;
use App\Llm\LlmUnavailable;
use App\Message\GardenProject;
use App\Search\NodeIndexer;
use App\Verify\MemoryVerifier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Nightly bounded self-improvement pass on one opt-in project:
 * 1) Re-indexes any missing/changed embeddings;
 * 2) Verifies up to MAX_VERIFY stale or unverified memories;
 * 3) Runs duplicate/contradiction detection once enough new memories accumulated since the last pass.
 */
#[AsMessageHandler]
final class GardenProjectHandler
{
    public const MAX_VERIFY = 5;
    public const MIN_NEW_MEMORIES_FOR_DUPLICATES = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeIndexer $indexer,
        private readonly MemoryVerifier $verifier,
        private readonly DuplicateFinder $duplicateFinder,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GardenProject $message): void
    {
        $project = $this->entityManager->find(Project::class, $message->projectId);
        if (null === $project || $project->isArchived() || !$project->isAutoGarden()) {
            return;
        }

        $lock = $this->lockFactory->createLock('auto-garden-'.$project->getId(), 600);
        if (!$lock->acquire()) {
            return;
        }

        try {
            $slug = $project->getSlug();
            $lastRun = $project->getLastAutoGardenAt();

            try {
                $indexed = $this->indexer->indexAll($project);
            } catch (LlmUnavailable) {
                $indexed = ['indexed' => 0, 'unchanged' => 0];
            }

            // indexAll clears the EntityManager between nodes: re-fetch the managed Project reference.
            $project = $this->entityManager->find(Project::class, $message->projectId);
            if (null === $project) {
                return;
            }

            $verified = ['checked' => 0, 'refuted' => 0, 'conflict' => 0];
            $duplicates = ['proposed' => 0];
            try {
                $verified = $this->verifier->verifyStale($project, self::MAX_VERIFY);
                if ($this->shouldRunDuplicatePass($project, $lastRun)) {
                    $duplicates = $this->duplicateFinder->find($project);
                }
            } catch (LlmUnavailable) {
                $this->logger->info('Auto-gardening skipped LLM checks for project {slug}: LLM not configured.', ['slug' => $slug]);
            }

            $project->markAutoGardened();
            $this->entityManager->flush();

            $this->logger->info('Auto-gardening completed for project {slug}: indexed={indexed}, verified={verified}, duplicates={duplicates}.', [
                'slug' => $slug,
                'indexed' => $indexed['indexed'],
                'verified' => $verified['checked'],
                'duplicates' => $duplicates['proposed'],
            ]);
        } finally {
            $lock->release();
        }
    }

    private function shouldRunDuplicatePass(Project $project, ?\DateTimeImmutable $lastRun): bool
    {
        $recent = (int) $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM node m
                WHERE m.type = :memory AND COALESCE(m.metadata->>'status', 'active') = 'active'
                  AND COALESCE(m.metadata->>'visibility', 'project') = 'project'
                  AND EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project)
                  AND (CAST(:since AS timestamp) IS NULL OR m.updated_at >= :since)
                SQL,
            ['memory' => NodeType::Memory->value, 'project' => $project->getId(), 'since' => $lastRun],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        return null === $lastRun ? $recent >= 2 : $recent >= self::MIN_NEW_MEMORIES_FOR_DUPLICATES;
    }
}
