<?php

namespace App\MessageHandler;

use App\Entity\Project;
use App\Message\ReindexNodes;
use App\Search\NodeIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReindexNodesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeIndexer $indexer,
    ) {
    }

    public function __invoke(ReindexNodes $message): void
    {
        $project = null === $message->projectId ? null : $this->entityManager->find(Project::class, $message->projectId);
        if (null === $message->projectId || null !== $project) {
            $this->indexer->indexAll($project);
        }
    }
}
