<?php

namespace App\Command;

use App\Entity\Project;
use App\Search\Embedder;
use App\Search\NodeIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:index', description: 'Embed memories, business rules and documents for semantic search')]
final class IndexCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeIndexer $indexer,
        private readonly Embedder $embedder,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug; every project and the shared nodes when omitted')]
        ?string $project = null,
    ): int {
        if (!$this->embedder->isAvailable()) {
            $io->error('No API key for the embedding platform (EMBEDDING_PLATFORM). Check with app:llm:check.');

            return Command::FAILURE;
        }
        $entity = null === $project ? null : $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null !== $project && null === $entity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }

        $report = $this->indexer->indexAll($entity);
        $io->success(\sprintf('%d embedded with %s, %d unchanged.', $report['indexed'], $this->embedder->model(), $report['unchanged']));

        return Command::SUCCESS;
    }
}
