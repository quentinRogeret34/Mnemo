<?php

namespace App\Command;

use App\Entity\Project;
use App\Llm\LlmUnavailable;
use App\Verify\MemoryVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:verify', description: 'Check the project memories never verified, or edited since, against the graph; refuted ones are proposed for withdrawal')]
final class VerifyCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemoryVerifier $verifier,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Option(description: 'Maximum number of memories to check (one LLM call each at most)')]
        int $limit = 20,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }

        try {
            $report = $this->verifier->verifyProject($projectEntity, max(1, $limit));
        } catch (LlmUnavailable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d memories checked: %d verified, %d refuted, %d conflicting, %d unverified. Refuted and conflicting ones are in /proposals.',
            $report['checked'],
            $report['verified'],
            $report['refuted'],
            $report['conflict'],
            $report['unverified'],
        ));

        return Command::SUCCESS;
    }
}
