<?php

namespace App\Command;

use App\Entity\Project;
use App\Learn\Learner;
use App\Learn\SourceKind;
use App\Llm\LlmUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:learn', description: 'Extract durable learnings from a conversation, file, diff or review into proposals to validate')]
final class LearnCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Learner $learner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Argument(description: 'Source kind: conversation, file, diff or review')]
        string $kind,
        #[Argument(description: 'Stable label shown with the proposals, e.g. "main...feature" or a transcript name')]
        string $label,
        #[Option(description: 'Read the source from this file instead of the standard input')]
        ?string $file = null,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }
        $sourceKind = SourceKind::tryFrom($kind);
        if (null === $sourceKind) {
            $io->error(\sprintf('Unknown source kind "%s", expected one of: %s.', $kind, implode(', ', array_column(SourceKind::cases(), 'value'))));

            return Command::FAILURE;
        }
        if (null !== $file && !is_file($file)) {
            $io->error(\sprintf('File "%s" not found.', $file));

            return Command::FAILURE;
        }

        $text = (string) file_get_contents($file ?? 'php://stdin');
        if ('' === trim($text)) {
            $io->error('The source is empty.');

            return Command::FAILURE;
        }

        try {
            $report = $this->learner->learn($sourceKind, $label, $text, $projectEntity);
        } catch (LlmUnavailable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($report['truncated']) {
            $io->warning('The source was truncated before extraction.');
        }
        $io->success(\sprintf(
            '%d learnings extracted: %d dropped (low score), %d already covered, %d already proposed, %d new proposals to validate in /proposals.',
            $report['extracted'],
            $report['dropped_low_score'],
            $report['covered'],
            $report['already_proposed'],
            $report['proposed'],
        ));

        return Command::SUCCESS;
    }
}
