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

#[AsCommand(name: 'app:demo', description: 'Load (or remove with --clear) the demo documents, rules, memories and proposals; without a project, into the "acme-shop" demo project with its code graph')]
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
        string $project = DemoData::PROJECT,
        #[Option(description: 'Remove the demo data instead of loading it')]
        bool $clear = false,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity && DemoData::PROJECT === $project && !$clear) {
            $projectEntity = $this->demoData->createProject();
            $io->note('Project "Acme Shop" created with the code graph of a fictional Symfony shop.');
        }
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }

        if ($clear) {
            $io->success(\sprintf('%d demo items removed.', $this->demoData->clear($projectEntity)));

            return Command::SUCCESS;
        }

        $result = $this->demoData->load($projectEntity);
        if ([] !== $result['missing_files']) {
            $io->warning(\sprintf('Code files not in the graph, links skipped: %s', implode(', ', $result['missing_files'])));
        }
        $io->success(\sprintf('Demo loaded: %d documents, %d rules, %d memories, %d proposals to review, %d rule-to-code links.', $result['documents'], $result['rules'], $result['memories'], $result['proposals'], $result['code_links']));

        return Command::SUCCESS;
    }
}
