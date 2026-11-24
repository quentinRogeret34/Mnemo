<?php

namespace App\Alignment;

use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeStatus;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Security\MemoryAccess;
use App\Security\ProjectScope;
use App\Security\SafeUrl;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Computes a deterministic Business <-> Dev alignment matrix across a project or all accessible projects:
 * Spec source -> Business validation -> Shadow areas -> Code implementation -> Drift / Recent PRs.
 */
final class AlignmentReport
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
        private readonly Connection $connection,
        private readonly ProjectScope $projectScope,
        private readonly MemoryAccess $memoryAccess,
    ) {
    }

    /**
     * @return array{
     *     summary: array{
     *         score: ?int,
     *         total: int,
     *         aligned: int,
     *         to_clarify: int,
     *         unlinked: int,
     *         drift: int,
     *         with_source: int,
     *         validated: int,
     *         with_code: int,
     *         blockers: int,
     *         refuted: int,
     *     },
     *     rows: list<array{
     *         id: int,
     *         code: string,
     *         title: string,
     *         status: string,
     *         project_slug: string,
     *         project_name: string,
     *         sources_count: int,
     *         code_count: int,
     *         verified_code_count: int,
     *         shadow_checked: bool,
     *         shadow_open: int,
     *         shadow_blockers: int,
     *         refuted_memories: int,
     *         decisions_count: int,
     *         last_ticket_ref: ?string,
     *         last_ticket_label: ?string,
     *         last_ticket_url: ?string,
     *         readiness: int,
     *         state: string,
     *         flags: list<string>,
     *     }>,
     * }
     */
    public function build(?Project $project = null, ?string $filter = null): array
    {
        $slugs = null === $project ? $this->projectScope->slugs() : null;
        if ([] === $slugs) {
            return ['summary' => $this->emptySummary(), 'rows' => []];
        }

        $scopeSql = null === $slugs ? '' : ' AND p.slug IN (:slugs)';
        $projectSql = null === $project ? ' AND p.archived_at IS NULL' : ' AND r.project_id = :project';

        $rawRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT r.id, r.external_id AS code, r.label AS title,
                       COALESCE(r.metadata->>'status', 'to_review') AS status,
                       (r.metadata->>'shadow_checked_at' IS NOT NULL) AS shadow_checked,
                       jsonb_array_length(COALESCE(r.metadata->'shadow_areas', '[]'::jsonb)) AS shadow_open,
                       (
                           SELECT COUNT(*)
                           FROM jsonb_array_elements(COALESCE(r.metadata->'shadow_areas', '[]'::jsonb)) AS sa
                           WHERE sa->>'severity' = 'blocker'
                       ) AS shadow_blockers,
                       p.slug AS project_slug,
                       p.name AS project_name,
                       (
                           SELECT COUNT(*)
                           FROM edge e
                           JOIN node d ON d.id = e.source_id
                           WHERE e.target_id = r.id AND e.relation = 'defines' AND e.status = :active AND d.type = :document
                       ) AS sources_count,
                       (
                           SELECT COUNT(*)
                           FROM edge e
                           JOIN node f ON f.id = e.target_id
                           WHERE e.source_id = r.id AND e.relation = 'implemented_by' AND e.status = :active AND f.type = :file
                       ) AS code_count,
                       (
                           SELECT COUNT(*)
                           FROM edge e
                           JOIN node f ON f.id = e.target_id
                           WHERE e.source_id = r.id AND e.relation = 'implemented_by' AND e.status = :active AND e.confidence = :verified AND f.type = :file
                       ) AS verified_code_count,
                       (
                           SELECT COUNT(*)
                           FROM edge e
                           JOIN node m ON m.id = e.source_id
                           WHERE e.target_id = r.id AND e.relation = 'about' AND e.status = :active
                             AND m.type = :memory AND COALESCE(m.metadata->>'status', 'active') = 'active'
                             AND m.metadata->'verification'->>'verdict' = 'refuted'
                             AND {$this->memoryAccess->sql('m')}
                       ) AS refuted_memories,
                       (
                           SELECT COUNT(*)
                           FROM edge e
                           JOIN node m ON m.id = e.source_id
                           WHERE e.target_id = r.id AND e.relation = 'about' AND e.status = :active
                             AND m.type = :memory AND COALESCE(m.metadata->>'status', 'active') = 'active'
                             AND m.metadata->>'type' = :decision
                             AND {$this->memoryAccess->sql('m')}
                       ) AS decisions_count,
                       latest_ticket.ref AS last_ticket_ref,
                       latest_ticket.label AS last_ticket_label,
                       latest_ticket.url AS last_ticket_url
                FROM node r
                JOIN project p ON p.id = r.project_id
                LEFT JOIN LATERAL (
                    SELECT t.external_id AS ref, t.label, t.metadata->>'url' AS url
                    FROM node t
                    WHERE t.project_id = r.project_id AND t.type = :ticket
                      AND (
                          EXISTS (
                              SELECT 1 FROM edge em
                              WHERE em.source_id = t.id AND em.target_id = r.id AND em.relation = 'mentions' AND em.status = :active
                          )
                          OR EXISTS (
                              SELECT 1
                              FROM edge impl
                              JOIN edge tf ON tf.source_id = t.id AND tf.target_id = impl.target_id AND tf.status = :active
                              WHERE impl.source_id = r.id AND impl.relation = 'implemented_by' AND impl.status = :active
                          )
                      )
                    ORDER BY t.created_at DESC
                    LIMIT 1
                ) latest_ticket ON true
                WHERE r.type = :rule{$projectSql}{$scopeSql}
                ORDER BY p.name ASC, r.external_id ASC
                SQL,
            [
                'rule' => NodeType::BusinessRule->value,
                'document' => NodeType::Document->value,
                'file' => NodeType::CodeFile->value,
                'memory' => NodeType::Memory->value,
                'ticket' => NodeType::Ticket->value,
                'decision' => MemoryType::Decision->value,
                'active' => EdgeStatus::Active->value,
                'verified' => Confidence::Verified->value,
            ] + (null === $project ? [] : ['project' => $project->getId()]) + (null === $slugs ? [] : ['slugs' => $slugs]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );

        $summary = $this->emptySummary();
        $rows = [];
        $readinessSum = 0;

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

            ++$summary['total'];
            $readinessSum += $readiness;
            if ($sourcesCount > 0) {
                ++$summary['with_source'];
            }
            if ($validated) {
                ++$summary['validated'];
            }
            if ($codeCount > 0) {
                ++$summary['with_code'];
            }
            $summary['blockers'] += $shadowBlockers;
            $summary['refuted'] += $refutedMemories;

            if (\in_array(self::FILTER_DRIFT, $flags, true)) {
                ++$summary['drift'];
            }
            if (\in_array(self::FILTER_TO_CLARIFY, $flags, true)) {
                ++$summary['to_clarify'];
            }
            if (\in_array(self::FILTER_UNLINKED, $flags, true)) {
                ++$summary['unlinked'];
            }
            if (\in_array(self::FILTER_ALIGNED, $flags, true)) {
                ++$summary['aligned'];
            }

            $rows[] = [
                'id' => (int) $raw['id'],
                'code' => (string) $raw['code'],
                'title' => (string) $raw['title'],
                'status' => (string) $raw['status'],
                'project_slug' => (string) $raw['project_slug'],
                'project_name' => (string) $raw['project_name'],
                'sources_count' => $sourcesCount,
                'code_count' => $codeCount,
                'verified_code_count' => $verifiedCodeCount,
                'shadow_checked' => $shadowChecked,
                'shadow_open' => $shadowOpen,
                'shadow_blockers' => $shadowBlockers,
                'refuted_memories' => $refutedMemories,
                'decisions_count' => $decisionsCount,
                'last_ticket_ref' => null !== $raw['last_ticket_ref'] ? (string) $raw['last_ticket_ref'] : null,
                'last_ticket_label' => null !== $raw['last_ticket_label'] ? (string) $raw['last_ticket_label'] : null,
                'last_ticket_url' => SafeUrl::http(null !== $raw['last_ticket_url'] ? (string) $raw['last_ticket_url'] : null),
                'readiness' => $readiness,
                'state' => $state,
                'flags' => $flags,
            ];
        }

        $summary['score'] = $summary['total'] > 0 ? (int) round($readinessSum / $summary['total']) : null;

        $priority = [
            self::FILTER_DRIFT => 0,
            self::FILTER_UNLINKED => 1,
            self::FILTER_TO_CLARIFY => 2,
            self::FILTER_ALIGNED => 3,
        ];
        usort($rows, static fn (array $a, array $b): int => ($priority[$a['state']] <=> $priority[$b['state']])
            ?: ($a['readiness'] <=> $b['readiness'])
            ?: strcmp($a['code'], $b['code']));

        if (null !== $filter && \in_array($filter, self::FILTERS, true)) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => \in_array($filter, $row['flags'], true)));
        }

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * @return array{score: ?int, total: int, aligned: int, to_clarify: int, unlinked: int, drift: int, with_source: int, validated: int, with_code: int, blockers: int, refuted: int}
     */
    private function emptySummary(): array
    {
        return [
            'score' => null,
            'total' => 0,
            'aligned' => 0,
            'to_clarify' => 0,
            'unlinked' => 0,
            'drift' => 0,
            'with_source' => 0,
            'validated' => 0,
            'with_code' => 0,
            'blockers' => 0,
            'refuted' => 0,
        ];
    }
}
