<?php

namespace App\Factory\Swarm;

use App\Entity\AgentAsset;
use App\Entity\Project;
use App\Repository\MemoryRepository;
use App\Repository\RuleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compiles Mnemo's authoritative knowledge (validated business rules, active decisions/conventions,
 * and project AgentAssets) into a concise Markdown brief injected into every Swarm planner, worker and reviewer.
 */
final class ProjectContextBuilder
{
    public function __construct(
        private readonly RuleRepository $ruleRepository,
        private readonly MemoryRepository $memoryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function build(Project $project, ?string $focusRuleCode = null): string
    {
        $sections = [
            \sprintf('Projet : %s (`%s`)', $project->getName(), $project->getSlug()),
        ];

        $rules = $this->ruleRepository->rules($project, null, 20);
        if ([] !== $rules) {
            $ruleLines = ['### Règles métier du projet'];
            foreach ($rules as $rule) {
                $code = $rule['code'];
                $isFocus = null !== $focusRuleCode && 0 === strcasecmp($code, $focusRuleCode);
                $prefix = $isFocus ? '★ [CIBLE] ' : '';
                $statement = trim((string) ($rule['statement'] ?? ''));
                $ruleLines[] = \sprintf(
                    '- %s**%s — %s** (%s)%s',
                    $prefix,
                    $code,
                    $rule['title'],
                    'validated' === $rule['status'] ? 'validée' : 'à valider',
                    '' !== $statement ? ' : '.mb_strimwidth($statement, 0, 220, '…') : '',
                );
            }
            $sections[] = implode("\n", $ruleLines);
        }

        $memories = $this->memoryRepository->findFiltered($project, null, '', false, null, null, 12);
        if ([] !== $memories) {
            $memLines = ['### Décisions & conventions actives'];
            foreach ($memories as $memory) {
                $memLines[] = \sprintf(
                    '- [%s] %s',
                    $memory['type'],
                    mb_strimwidth(trim($memory['text']), 0, 220, '…'),
                );
            }
            $sections[] = implode("\n", $memLines);
        }

        $assets = $this->entityManager->getRepository(AgentAsset::class)->findBy(['project' => $project], ['name' => 'ASC'], 6);
        if ([] !== $assets) {
            $assetLines = ['### Skills & instructions d’agents'];
            foreach ($assets as $asset) {
                $assetLines[] = \sprintf('- **%s** (%s) : %s', $asset->getName(), $asset->getKind()->value, mb_strimwidth($asset->getDescription() ?: $asset->getContent(), 0, 180, '…'));
            }
            $sections[] = implode("\n", $assetLines);
        }

        return implode("\n\n", $sections);
    }
}
