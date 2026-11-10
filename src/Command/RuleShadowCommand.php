<?php

namespace App\Command;

use App\Entity\Project;
use App\Llm\LlmUnavailable;
use App\Rule\ShadowAreaDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:rule:shadow', description: 'Find the shadow areas of business rules (assumptions, ambiguous terms, missing edge cases...): one LLM call per rule')]
final class RuleShadowCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShadowAreaDetector $detector,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project slug')]
        string $project,
        #[Argument(description: 'Rule code, e.g. RG-001; all rules of the project when omitted')]
        ?string $code = null,
    ): int {
        $projectEntity = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]);
        if (null === $projectEntity) {
            $io->error(\sprintf('Project "%s" not found.', $project));

            return Command::FAILURE;
        }
        $rules = $this->detector->rules($projectEntity, $code);
        if ([] === $rules) {
            $io->error(null === $code ? 'The project has no business rule.' : \sprintf('Rule "%s" not found.', $code));

            return Command::FAILURE;
        }

        $rows = [];
        try {
            foreach ($rules as $rule) {
                $areas = $this->detector->analyze($rule);
                $rows[] = [$rule->getExternalId(), \count($areas), \count(array_filter($areas, static fn (array $area): bool => 'blocker' === $area['severity']))];
            }
        } catch (LlmUnavailable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Rule', 'Shadow areas', 'Blockers'], $rows);

        return Command::SUCCESS;
    }
}
