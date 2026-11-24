<?php

namespace App\Alignment;

use App\Entity\Project;
use App\Repository\RuleRepository;
use App\Security\ProjectScope;
use App\Security\SafeUrl;

/**
 * Computes a deterministic Business <-> Dev alignment matrix across a project or all accessible projects:
 * Spec source -> Business validation -> Shadow areas -> Code implementation -> Drift / Recent PRs.
 */
final readonly class AlignmentReport
{
    public const FILTER_DRIFT = 'drift';
    public const FILTER_TO_CLARIFY = 'to_clarify';
    public const FILTER_UNLINKED = 'unlinked';
    public const FILTER_ALIGNED = 'aligned';

    public const FILTERS = [
        self::FILTER_DRIFT,
        self::FILTER_TO_CLARIFY,
        self::FILTER_UNLINKED,
        self::FILTER_ALIGNED,
    ];

    public function __construct(
        private RuleRepository $ruleRepository,
        private ProjectScope $projectScope,
    ) {
    }

    public function build(?Project $project = null, ?string $filter = null): AlignmentResult
    {
        $slugs = null === $project ? $this->projectScope->slugs() : null;
        $rawRows = $this->ruleRepository->alignmentRows($project, $slugs);
        if ([] === $rawRows) {
            return new AlignmentResult(new AlignmentSummary(), []);
        }

        $total = $aligned = $toClarify = $unlinked = $drift = $withSource = $validatedCount = $withCode = $blockers = $refuted = $readinessSum = 0;
        $rows = [];

        foreach ($rawRows as $raw) {
            $sourcesCount = (int) $raw['sources_count'];
            $codeCount = (int) $raw['code_count'];
            $verifiedCodeCount = (int) $raw['verified_code_count'];
            $shadowChecked = (bool) $raw['shadow_checked'];
            $shadowOpen = (int) $raw['shadow_open'];
            $shadowBlockers = (int) $raw['shadow_blockers'];
            $refutedMemories = (int) $raw['refuted_memories'];
            $decisionsCount = (int) $raw['decisions_count'];
            $validated = 'validated' === $raw['status'];

            $readiness = 0;
            if ($sourcesCount > 0) {
                $readiness += 20;
            }
            if ($validated) {
                $readiness += 25;
            }
            if ($codeCount > 0) {
                $readiness += $verifiedCodeCount > 0 ? 30 : 20;
            }
            if (0 === $shadowOpen) {
                $readiness += $shadowChecked ? 15 : 8;
            } elseif (0 === $shadowBlockers) {
                $readiness += 5;
            }
            if (0 === $refutedMemories) {
                $readiness += 10;
            }

            $flags = [];
            if ($shadowBlockers > 0 || $refutedMemories > 0) {
                $flags[] = self::FILTER_DRIFT;
            }
            if (!$validated || $shadowOpen > 0) {
                $flags[] = self::FILTER_TO_CLARIFY;
            }
            if (0 === $codeCount || 0 === $sourcesCount) {
                $flags[] = self::FILTER_UNLINKED;
            }
            if ([] === $flags) {
                $flags[] = self::FILTER_ALIGNED;
            }

            $state = match (true) {
                \in_array(self::FILTER_DRIFT, $flags, true) => self::FILTER_DRIFT,
                \in_array(self::FILTER_UNLINKED, $flags, true) => self::FILTER_UNLINKED,
                \in_array(self::FILTER_TO_CLARIFY, $flags, true) => self::FILTER_TO_CLARIFY,
                default => self::FILTER_ALIGNED,
            };

            ++$total;
            $readinessSum += $readiness;
            $withSource += $sourcesCount > 0 ? 1 : 0;
            $validatedCount += $validated ? 1 : 0;
            $withCode += $codeCount > 0 ? 1 : 0;
            $blockers += $shadowBlockers;
            $refuted += $refutedMemories;
            $drift += \in_array(self::FILTER_DRIFT, $flags, true) ? 1 : 0;
            $toClarify += \in_array(self::FILTER_TO_CLARIFY, $flags, true) ? 1 : 0;
            $unlinked += \in_array(self::FILTER_UNLINKED, $flags, true) ? 1 : 0;
            $aligned += \in_array(self::FILTER_ALIGNED, $flags, true) ? 1 : 0;

            $rows[] = new AlignmentRow(
                id: (int) $raw['id'],
                code: (string) $raw['code'],
                title: (string) $raw['title'],
                status: (string) $raw['status'],
                projectSlug: (string) $raw['project_slug'],
                projectName: (string) $raw['project_name'],
                sourcesCount: $sourcesCount,
                codeCount: $codeCount,
                verifiedCodeCount: $verifiedCodeCount,
                shadowChecked: $shadowChecked,
                shadowOpen: $shadowOpen,
                shadowBlockers: $shadowBlockers,
                refutedMemories: $refutedMemories,
                decisionsCount: $decisionsCount,
                lastTicketRef: null !== $raw['last_ticket_ref'] ? (string) $raw['last_ticket_ref'] : null,
                lastTicketLabel: null !== $raw['last_ticket_label'] ? (string) $raw['last_ticket_label'] : null,
                lastTicketUrl: SafeUrl::http(null !== $raw['last_ticket_url'] ? (string) $raw['last_ticket_url'] : null),
                readiness: $readiness,
                state: $state,
                flags: $flags,
            );
        }

        $priority = [
            self::FILTER_DRIFT => 0,
            self::FILTER_UNLINKED => 1,
            self::FILTER_TO_CLARIFY => 2,
            self::FILTER_ALIGNED => 3,
        ];
        usort($rows, static fn (AlignmentRow $a, AlignmentRow $b): int => ($priority[$a->state] <=> $priority[$b->state])
            ?: ($a->readiness <=> $b->readiness)
            ?: strcmp($a->code, $b->code));

        if (null !== $filter && \in_array($filter, self::FILTERS, true)) {
            $rows = array_values(array_filter($rows, static fn (AlignmentRow $row): bool => \in_array($filter, $row->flags, true)));
        }

        return new AlignmentResult(
            new AlignmentSummary(
                score: (int) round($readinessSum / $total),
                total: $total,
                aligned: $aligned,
                toClarify: $toClarify,
                unlinked: $unlinked,
                drift: $drift,
                withSource: $withSource,
                validated: $validatedCount,
                withCode: $withCode,
                blockers: $blockers,
                refuted: $refuted,
            ),
            $rows,
        );
    }
}
