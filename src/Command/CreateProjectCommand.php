<?php

namespace App\Command;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:project:create', description: 'Register a project (one git repository)')]
final class CreateProjectCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Unique slug, e.g. claude-strava-coach')]
        string $slug,
        #[Argument(description: 'Display name')]
        string $name,
        #[Argument(description: 'Path of the git repository')]
        string $repositoryPath,
    ): int {
        if (null !== $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug])) {
            $io->error(\sprintf('Project "%s" already exists.', $slug));

            return Command::FAILURE;
        }

        $this->entityManager->persist(new Project($slug, $name, $repositoryPath));
        $this->entityManager->flush();

        $io->success(\sprintf('Project "%s" created.', $slug));

        return Command::SUCCESS;
    }
}
