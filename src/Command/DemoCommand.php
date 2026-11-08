<?php

namespace App\Command;

use App\Demo\DemoData;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo', description: 'Load (or remove with --clear) demo business documents and rules for a project')]
final class DemoCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DemoData $demoData,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Option(description: 'Remove the demo data instead of loading it')]
        bool $clear = false,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }

        if ($clear) {
            $io->success(\sprintf('%d demo nodes removed.', $this->demoData->clear($projectEntity)));

            return Command::SUCCESS;
        }

        $result = $this->demoData->load($projectEntity);
        if ([] !== $result['missing_files']) {
            $io->warning(\sprintf('Code files not in the graph, links skipped: %s', implode(', ', $result['missing_files'])));
        }
        $io->success(\sprintf('Demo loaded: %d documents, %d rules, %d memories, %d rule-to-code links.', $result['documents'], $result['rules'], $result['memories'], $result['code_links']));

        return Command::SUCCESS;
    }
}
