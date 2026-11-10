<?php

namespace App\Command;

use App\Entity\Project;
use App\Garden\DuplicateFinder;
use App\Garden\GardenChecker;
use App\Llm\LlmUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:garden', description: 'Report what drifted in a project knowledge (changes nothing); with --llm, propose duplicate and contradicting memories')]
final class GardenCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GardenChecker $checker,
        private readonly DuplicateFinder $duplicateFinder,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Option(description: 'Also ask the LLM for duplicate and contradicting memories')]
        bool $llm = false,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }

        $findings = $this->checker->check($projectEntity);
        if ([] === $findings) {
            $io->success('Nothing drifted.');
        } else {
            $io->table(['Finding', 'Count', 'Examples'], array_map(
                static fn (array $finding): array => [$finding['title'], $finding['count'], implode(', ', array_map(static fn (array $item): string => $item['code'] ?? mb_strimwidth($item['label'], 0, 50, '...'), \array_slice($finding['items'], 0, 3)))],
                $findings,
            ));
        }

        if ($llm) {
            try {
                $report = $this->duplicateFinder->find($projectEntity);
            } catch (LlmUnavailable $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }
            $io->success(\sprintf('%d memories compared: %d new proposals, %d already proposed.', $report['compared'], $report['proposed'], $report['already_proposed']));
        }

        return Command::SUCCESS;
    }
}
