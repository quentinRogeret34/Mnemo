<?php

namespace App\Command;

use App\Entity\Project;
use App\Ingest\GraphifyImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ingest:code', description: 'Import a graphify graph.json (graphify extract --code-only) into a project code graph')]
final class IngestCodeCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GraphifyImporter $importer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Argument(description: 'Path to graphify-out/graph.json')]
        string $graph,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found. Create it with app:project:create.', $project));

            return Command::FAILURE;
        }

        if (!is_file($graph)) {
            $io->error(\sprintf('File "%s" not found.', $graph));

            return Command::FAILURE;
        }

        $result = $this->importer->import($projectEntity, $graph);

        $io->success(\sprintf(
            'Imported graph at commit %s: nodes %d created, %d updated, %d deleted; edges %d created, %d deleted.',
            $result->commit ?? 'unknown',
            $result->nodesCreated,
            $result->nodesUpdated,
            $result->nodesDeleted,
            $result->edgesCreated,
            $result->edgesDeleted,
        ));
        if ($result->manualEdgesMoved > 0) {
            $io->note(\sprintf('%d manual links moved to the renamed nodes of the same file.', $result->manualEdgesMoved));
        }
        if ($result->manualEdgesLost > 0) {
            $io->warning(\sprintf('%d manual links (documents, memories, rules) removed with the nodes that disappeared.', $result->manualEdgesLost));
        }

        return Command::SUCCESS;
    }
}
