<?php

namespace App\Command;

use App\Entity\Project;
use App\Export\MemoryBlockExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'app:export:memory-block', description: 'Print the project memory block, or write it into agent context files (CLAUDE.md, AGENTS.md...) between its markers')]
final class ExportMemoryBlockCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemoryBlockExporter $exporter,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<string> $write
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Option(description: 'Context file to create or update, repeatable (e.g. --write=../repo/CLAUDE.md --write=../repo/AGENTS.md)')]
        array $write = [],
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }

        $block = $this->exporter->render($projectEntity);
        if ([] === $write) {
            $io->write($block);

            return Command::SUCCESS;
        }

        foreach ($write as $file) {
            $before = is_file($file) ? (string) file_get_contents($file) : '';
            $after = $this->exporter->upsert($before, $block);
            if ($after === $before) {
                $io->writeln(\sprintf('%s: unchanged', $file));
                continue;
            }
            $this->filesystem->dumpFile($file, $after);
            $io->writeln(\sprintf('%s: %s', $file, '' === $before ? 'created' : 'updated'));
        }

        return Command::SUCCESS;
    }
}
