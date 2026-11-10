<?php

namespace App\Command;

use App\Entity\Project;
use App\Ingest\RepoContextImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ingest:context', description: 'Import an AIDD memory bank (aidd_docs/) and the agent conventions (CLAUDE.md, AGENTS.md, rules) of a repository')]
final class IngestContextCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepoContextImporter $importer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Argument(description: 'Path to the repository root, as seen from where the command runs')]
        string $path,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }
        if (!is_dir($path)) {
            $io->error(\sprintf('Directory "%s" not found.', $path));

            return Command::FAILURE;
        }

        $result = $this->importer->import($projectEntity, $path);

        $io->success(\sprintf(
            'Memories %d created, %d unchanged; decisions %d created, %d updated, %d unchanged; documents %d created, %d updated, %d unchanged.',
            $result->memoriesCreated,
            $result->memoriesUnchanged,
            $result->decisionsCreated,
            $result->decisionsUpdated,
            $result->decisionsUnchanged,
            $result->documentsCreated,
            $result->documentsUpdated,
            $result->documentsUnchanged,
        ));
        if ([] !== $result->skipped) {
            $io->warning(['Skipped:', ...$result->skipped]);
        }
        if ([] !== $result->missing) {
            $io->note(['Imported before and gone from the repository (kept in the graph):', ...$result->missing]);
        }

        return Command::SUCCESS;
    }
}
