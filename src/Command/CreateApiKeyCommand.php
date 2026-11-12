<?php

namespace App\Command;

use App\Entity\ApiKey;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:api-key:create', description: 'Create an API key for agents calling /mcp and /api')]
final class CreateApiKeyCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $project
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Who or what uses the key, e.g. claude-code-laptop')]
        string $name,
        #[Option(description: 'Project slug the key is limited to; repeat for several, omit for all projects')]
        array $project = [],
    ): int {
        [$key, $token] = ApiKey::generate($name);
        foreach ($project as $slug) {
            $entity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug]);
            if (null === $entity) {
                $io->error(\sprintf('Project "%s" not found.', $slug));

                return Command::FAILURE;
            }
            $key->addProject($entity);
        }
        $this->entityManager->persist($key);
        $this->entityManager->flush();

        $io->success(\sprintf('Key "%s" created. The token is shown once:', $name));
        $io->writeln($token);

        return Command::SUCCESS;
    }
}
