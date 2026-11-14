<?php

namespace App\Command;

use App\Entity\Connector;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Review\PullRequestReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:review', description: 'Review a pull request as the GitHub App: comment and check, as the webhook does')]
final class ReviewCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PullRequestReviewer $reviewer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Argument(description: 'Number of the pull request')]
        int $number,
        #[Option(description: 'Print the comment without publishing it nor the check')]
        bool $dryRun = false,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        $connector = null === $projectEntity ? null : $this->entityManager->getRepository(Connector::class)->findOneBy(['project' => $projectEntity, 'mode' => ConnectorMode::GitHub], ['id' => 'ASC']);
        if (null === $connector) {
            $io->error(\sprintf('Project "%s" has no GitHub connector.', $project));

            return Command::FAILURE;
        }

        try {
            $io->writeln($this->reviewer->review($connector, $number, !$dryRun));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
