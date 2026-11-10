<?php

namespace App\Learn;

use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Source to proposals: extraction, scoring, reconciliation. Nothing enters the graph before a human applies a proposal.
 */
final class Learner
{
    public function __construct(
        private readonly LearningExtractor $extractor,
        private readonly LearningReconciler $reconciler,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{extracted: int, dropped_low_score: int, covered: int, already_proposed: int, proposed: int, truncated: bool}
     */
    public function learn(SourceKind $kind, string $label, string $text, Project $project): array
    {
        $extraction = $this->extractor->extract($kind, $label, $text, $project);
        $report = [
            'extracted' => \count($extraction['candidates']) + $extraction['dropped'],
            'dropped_low_score' => $extraction['dropped'],
            'covered' => 0,
            'already_proposed' => 0,
            'proposed' => 0,
            'truncated' => $extraction['truncated'],
        ];

        $pending = $this->pendingLessons($project);
        foreach ($extraction['candidates'] as $candidate) {
            $key = mb_strtolower($candidate->lesson);
            if (isset($pending[$key])) {
                ++$report['already_proposed'];
                continue;
            }

            $reconciliation = $this->reconciler->reconcile($candidate, $project);
            if ('covered' === $reconciliation['verdict']) {
                ++$report['covered'];
                continue;
            }

            $this->entityManager->persist(new Proposal($project, ProposalKind::Learning, [
                'lesson' => $candidate->lesson,
                'evidence' => $candidate->evidence,
                'type' => $candidate->type->value,
                'score' => $candidate->score(),
                'axes' => ['durability' => $candidate->durability, 'reuse' => $candidate->reuse, 'project_fit' => $candidate->projectFit, 'risk' => $candidate->risk],
                'files' => $candidate->files,
                'rules' => $candidate->rules,
                'adr' => $candidate->adr,
                'reconciliation' => $reconciliation['verdict'],
                'target_id' => $reconciliation['target_id'],
                'target_text' => $reconciliation['target_text'],
                'reason' => $reconciliation['reason'],
                'source' => ['kind' => $kind->value, 'label' => $label],
            ]));
            $pending[$key] = true;
            ++$report['proposed'];
        }
        $this->entityManager->flush();

        return $report;
    }

    /**
     * @return array<string, true>
     */
    private function pendingLessons(Project $project): array
    {
        $lessons = [];
        foreach ($this->entityManager->getRepository(Proposal::class)->findBy(['project' => $project, 'kind' => ProposalKind::Learning, 'status' => ProposalStatus::Pending]) as $proposal) {
            $lessons[mb_strtolower($proposal->getPayload()['lesson'])] = true;
        }

        return $lessons;
    }
}
