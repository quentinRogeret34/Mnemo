<?php

namespace App\Dashboard;

use App\Entity\Project;
use App\Enum\NodeType;
use App\Security\MemoryAccess;
use App\Security\ProjectScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Figures of the dashboard: memories stored, business alignment signals, and what agents asked the MCP server.
 */
final class DashboardReader
{
    private const ACTIVE_MEMORY = "m.type = 'memory' AND COALESCE(m.metadata->>'status', 'active') = 'active'";
    private const IN_PROJECT = '(CAST(:project AS int) IS NULL OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project))';
    private const CALL_SCOPE = '(CAST(:slug AS text) IS NULL OR c.project = :slug) AND (CAST(:from AS timestamp) IS NULL OR c.created_at >= :from) AND (CAST(:to AS timestamp) IS NULL OR c.created_at < :to)';
    private const TIMESTAMPS = ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE];

    public function __construct(
        private readonly Connection $connection,
        private readonly MemoryAccess $memoryAccess,
        private readonly ProjectScope $projectScope,
    ) {
    }

    /**
     * @param ?\DateTimeImmutable $to end of the window, excluded: the previous period ends where the current one starts
     *
     * @return array{memories: int, memories_added: int, recalled: int, lookups: int, requests: int, failed: int, pending: int}
     */
    public function summary(?Project $project, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        $active = self::ACTIVE_MEMORY.' AND '.$this->memoryAccess->sql('m');
        $inProject = self::IN_PROJECT;
        $scope = self::CALL_SCOPE;

        return array_map('intval', $this->connection->fetchAssociative(
            <<<SQL
                SELECT (SELECT COUNT(*) FROM node m WHERE {$active} AND {$inProject}) AS memories,
                       (SELECT COUNT(*) FROM node m WHERE {$active} AND {$inProject} AND (CAST(:from AS timestamp) IS NULL OR m.created_at >= :from) AND (CAST(:to AS timestamp) IS NULL OR m.created_at < :to)) AS memories_added,
                       calls.recalled,
                       calls.lookups,
                       calls.requests,
                       calls.failed,
                       (SELECT COUNT(*) FROM proposal p WHERE p.status = 'pending' AND (CAST(:project AS int) IS NULL OR p.project_id = :project)) AS pending
                FROM (
                    SELECT COALESCE(SUM(c.memories), 0) AS recalled,
                           COUNT(*) FILTER (WHERE c.tool IN ('search', 'file_context', 'get_document')) AS lookups,
                           COUNT(*) AS requests,
                           COUNT(*) FILTER (WHERE c.failed) AS failed
                    FROM mcp_call c WHERE {$scope}
                ) calls
                SQL,
            ['project' => $project?->getId()] + $this->parameters($project, $from, $to),
            self::TIMESTAMPS,
        ));
    }

    /**
     * MCP calls per hour or day, empty buckets included.
     *
     * @return list<array{at: string, value: int}>
     */
    public function requestSeries(?Project $project, \DateTimeImmutable $from, bool $hourly): array
    {
        return $this->series('mcp_call c', 'c.created_at', '(CAST(:slug AS text) IS NULL OR c.project = :slug)', $project, $from, $hourly);
    }

    /**
     * Memories handed back to agents per hour or day, empty buckets included.
     *
     * @return list<array{at: string, value: int}>
     */
    public function recallSeries(?Project $project, \DateTimeImmutable $from, bool $hourly): array
    {
        return $this->series('mcp_call c', 'c.created_at', '(CAST(:slug AS text) IS NULL OR c.project = :slug)', $project, $from, $hourly, 'c.memories');
    }

    /**
     * Memories created per hour or day, empty buckets included.
     *
     * @return list<array{at: string, value: int}>
     */
    public function memorySeries(?Project $project, \DateTimeImmutable $from, bool $hourly): array
    {
        return $this->series('node m', 'm.created_at', "m.type = 'memory' AND ".$this->memoryAccess->sql('m').' AND '.self::IN_PROJECT, $project, $from, $hourly);
    }

    public function firstActivity(): ?\DateTimeImmutable
    {
        $first = $this->connection->fetchOne('SELECT LEAST((SELECT MIN(created_at) FROM mcp_call), (SELECT MIN(created_at) FROM node WHERE type = :memory))', ['memory' => NodeType::Memory->value]);

        return null === $first || false === $first ? null : new \DateTimeImmutable($first);
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    public function memoryTypes(?Project $project): array
    {
        $active = self::ACTIVE_MEMORY.' AND '.$this->memoryAccess->sql('m');
        $inProject = self::IN_PROJECT;

        return $this->counts(
            "SELECT COALESCE(m.metadata->>'type', 'fact') AS label, COUNT(*) AS value FROM node m WHERE {$active} AND {$inProject} GROUP BY 1 ORDER BY 2 DESC, 1",
            ['project' => $project?->getId()],
        );
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    public function tools(?Project $project, ?\DateTimeImmutable $from): array
    {
        return $this->counts('SELECT c.tool AS label, COUNT(*) AS value FROM mcp_call c WHERE '.self::CALL_SCOPE.' GROUP BY 1 ORDER BY 2 DESC, 1', $this->parameters($project, $from));
    }

    /**
     * Node types asked to the search tool: a search over every type counts once per type.
     *
     * @return list<array{label: string, value: int}>
     */
    public function searchTypes(?Project $project, ?\DateTimeImmutable $from): array
    {
        return $this->counts(
            'SELECT t.value AS label, COUNT(*) AS value FROM mcp_call c CROSS JOIN LATERAL json_array_elements_text(c.search_types) AS t(value) WHERE c.search_types IS NOT NULL AND '.self::CALL_SCOPE.' GROUP BY 1 ORDER BY 2 DESC, 1',
            $this->parameters($project, $from),
        );
    }

    /**
     * How the search tool matched: keywords alone, or keywords and meaning.
     *
     * @return list<array{label: string, value: int}>
     */
    public function searchModes(?Project $project, ?\DateTimeImmutable $from): array
    {
        return $this->counts('SELECT c.mode AS label, COUNT(*) AS value FROM mcp_call c WHERE c.mode IS NOT NULL AND '.self::CALL_SCOPE.' GROUP BY 1 ORDER BY 2 DESC, 1', $this->parameters($project, $from));
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    public function pendingKinds(?Project $project): array
    {
        return $this->counts(
            "SELECT p.kind AS label, COUNT(*) AS value FROM proposal p WHERE p.status = 'pending' AND (CAST(:project AS int) IS NULL OR p.project_id = :project) GROUP BY 1 ORDER BY 2 DESC, 1",
            ['project' => $project?->getId()],
        );
    }

    /**
     * Newest pending proposals, with the memory text each one is about.
     *
     * @return list<array{id: int, kind: string, text: ?string, created_at: string}>
     */
    public function latestProposals(?Project $project, int $limit): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                "SELECT p.id, p.kind, COALESCE(p.payload->>'lesson', p.payload->>'memory_text', p.payload->>'a_text', p.payload->>'target_text') AS text, p.created_at
                 FROM proposal p WHERE p.status = 'pending' AND (CAST(:project AS int) IS NULL OR p.project_id = :project)
                 ORDER BY p.created_at DESC, p.id DESC LIMIT $limit",
                ['project' => $project?->getId()],
            ),
        );
    }

    /**
     * Connectors whose last run ended on an error.
     *
     * @return list<array{id: int, name: string, error: string, last_run_at: string}>
     */
    public function failingConnectors(?Project $project): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                "SELECT c.id, c.name, c.last_report->>'error' AS error, c.last_run_at FROM connector c
                 WHERE c.last_report->>'error' IS NOT NULL AND (CAST(:project AS int) IS NULL OR c.project_id = :project)
                 ORDER BY c.last_run_at DESC",
                ['project' => $project?->getId()],
            ),
        );
    }

    public function refutedMemories(?Project $project): int
    {
        $active = self::ACTIVE_MEMORY.' AND '.$this->memoryAccess->sql('m');
        $inProject = self::IN_PROJECT;

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM node m WHERE {$active} AND {$inProject} AND m.metadata->'verification'->>'verdict' = 'refuted'",
            ['project' => $project?->getId()],
        );
    }

    /**
     * Self-improvement signals over the selected window: proposal acceptance / human edits and assistant ratings.
     *
     * @return array{applied: int, rejected: int, edited: int, acceptance_rate: ?float, thumbs_up: int, thumbs_down: int, satisfaction_rate: ?float}
     */
    public function aiQuality(?Project $project, ?\DateTimeImmutable $from): array
    {
        $proposalRow = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*) FILTER (WHERE p.status = 'applied') AS applied,
                       COUNT(*) FILTER (WHERE p.status = 'rejected') AS rejected,
                       COUNT(*) FILTER (WHERE p.status = 'applied' AND (p.payload->'human_feedback'->>'edited')::boolean IS TRUE) AS edited
                FROM proposal p
                WHERE (CAST(:project AS int) IS NULL OR p.project_id = :project)
                  AND (CAST(:from AS timestamp) IS NULL OR p.decided_at >= :from)
                SQL,
            ['project' => $project?->getId(), 'from' => $from],
            ['from' => Types::DATETIME_IMMUTABLE],
        );

        $chatRow = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*) FILTER (WHERE msg->>'feedback' = 'up') AS thumbs_up,
                       COUNT(*) FILTER (WHERE msg->>'feedback' = 'down') AS thumbs_down
                FROM chat_conversation c
                CROSS JOIN LATERAL jsonb_array_elements(c.messages) AS msg
                WHERE msg->>'role' = 'assistant'
                  AND (CAST(:project AS int) IS NULL OR c.project_id = :project)
                  AND (CAST(:from AS timestamp) IS NULL OR c.updated_at >= :from)
                SQL,
            ['project' => $project?->getId(), 'from' => $from],
            ['from' => Types::DATETIME_IMMUTABLE],
        );

        $applied = (int) ($proposalRow['applied'] ?? 0);
        $rejected = (int) ($proposalRow['rejected'] ?? 0);
        $edited = (int) ($proposalRow['edited'] ?? 0);
        $decided = $applied + $rejected;

        $up = (int) ($chatRow['thumbs_up'] ?? 0);
        $down = (int) ($chatRow['thumbs_down'] ?? 0);
        $rated = $up + $down;

        return [
            'applied' => $applied,
            'rejected' => $rejected,
            'edited' => $edited,
            'acceptance_rate' => $decided > 0 ? round($applied / $decided * 100, 1) : null,
            'thumbs_up' => $up,
            'thumbs_down' => $down,
            'satisfaction_rate' => $rated > 0 ? round($up / $rated * 100, 1) : null,
        ];
    }

    /**
     * Business-facing overview: rule coverage, validation progress, documents, and open shadow-area questions.
     *
     * @return array{rules_total: int, rules_validated: int, rules_to_review: int, rules_without_code: int, documents_total: int, documents_added: int, shadow_open: int, shadow_blockers: int, alignment_score: ?int}
     */
    public function businessSummary(?Project $project, ?\DateTimeImmutable $from): array
    {
        $slugs = null === $project ? $this->projectScope->slugs() : null;
        if ([] === $slugs) {
            return [
                'rules_total' => 0, 'rules_validated' => 0, 'rules_to_review' => 0, 'rules_without_code' => 0,
                'documents_total' => 0, 'documents_added' => 0, 'shadow_open' => 0, 'shadow_blockers' => 0, 'alignment_score' => null,
            ];
        }

        $ruleScope = null === $slugs ? '' : ' AND p.slug IN (:slugs)';
        $docScope = null === $slugs ? '' : ' AND EXISTS (SELECT 1 FROM node_project np2 JOIN project p2 ON p2.id = np2.project_id WHERE np2.node_id = d.id AND p2.slug IN (:slugs))';
        $scopeParams = null === $slugs ? [] : ['slugs' => $slugs];
        $scopeTypes = null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING];

        $rulesRow = $this->connection->fetchAssociative(
            <<<SQL
                SELECT COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE r.metadata->>'status' = 'validated') AS validated,
                       COUNT(*) FILTER (WHERE r.metadata->>'status' = 'to_review') AS to_review,
                       COUNT(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = r.id AND e.relation = 'implemented_by' AND e.status = 'active')) AS without_code,
                       COALESCE(SUM(CASE WHEN jsonb_typeof(r.metadata->'shadow_areas') = 'array' THEN jsonb_array_length(r.metadata->'shadow_areas') ELSE 0 END), 0) AS shadow_open,
                       COALESCE(SUM(CASE WHEN jsonb_typeof(r.metadata->'shadow_areas') = 'array'
                           THEN (SELECT COUNT(*) FROM jsonb_array_elements(r.metadata->'shadow_areas') sa WHERE sa->>'severity' = 'blocker')
                           ELSE 0 END), 0) AS shadow_blockers
                FROM node r
                JOIN project p ON p.id = r.project_id
                WHERE r.type = 'business_rule'
                  AND (CAST(:project AS int) IS NULL OR r.project_id = :project){$ruleScope}
                SQL,
            ['project' => $project?->getId()] + $scopeParams,
            $scopeTypes,
        );

        $docsRow = $this->connection->fetchAssociative(
            <<<SQL
                SELECT COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE CAST(:from AS timestamp) IS NULL OR d.created_at >= :from) AS added
                FROM node d
                WHERE d.type = 'document'
                  AND (CAST(:project AS int) IS NULL OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id AND np.project_id = :project)){$docScope}
                SQL,
            ['project' => $project?->getId(), 'from' => $from] + $scopeParams,
            ['from' => Types::DATETIME_IMMUTABLE] + $scopeTypes,
        );

        $total = (int) ($rulesRow['total'] ?? 0);
        $validated = (int) ($rulesRow['validated'] ?? 0);
        $withoutCode = (int) ($rulesRow['without_code'] ?? 0);
        $shadowBlockers = (int) ($rulesRow['shadow_blockers'] ?? 0);

        $alignmentScore = null;
        if ($total > 0) {
            $validatedRatio = $validated / $total;
            $linkedRatio = ($total - $withoutCode) / $total;
            $blockerPenalty = min(0.3, $shadowBlockers * 0.08);
            $alignmentScore = (int) max(0, min(100, round(($validatedRatio * 0.5 + $linkedRatio * 0.5 - $blockerPenalty) * 100)));
        }

        return [
            'rules_total' => $total,
            'rules_validated' => $validated,
            'rules_to_review' => (int) ($rulesRow['to_review'] ?? 0),
            'rules_without_code' => $withoutCode,
            'documents_total' => (int) ($docsRow['total'] ?? 0),
            'documents_added' => (int) ($docsRow['added'] ?? 0),
            'shadow_open' => (int) ($rulesRow['shadow_open'] ?? 0),
            'shadow_blockers' => $shadowBlockers,
            'alignment_score' => $alignmentScore,
        ];
    }

    /**
     * Open shadow-area questions awaiting a business answer, ordered by severity (blocker first).
     *
     * @return list<array{rule_code: string, rule_title: string, project_slug: string, project_name: string, severity: string, category: string, gap: string, question: string}>
     */
    public function openShadowQuestions(?Project $project, int $limit = 6): array
    {
        $slugs = null === $project ? $this->projectScope->slugs() : null;
        if ([] === $slugs) {
            return [];
        }
        $scopeSql = null === $slugs ? '' : ' AND p.slug IN (:slugs)';

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT r.external_id AS rule_code, r.label AS rule_title, p.slug AS project_slug, p.name AS project_name,
                       sa->>'severity' AS severity, sa->>'category' AS category, sa->>'gap' AS gap, sa->>'question' AS question
                FROM node r
                JOIN project p ON p.id = r.project_id
                CROSS JOIN LATERAL jsonb_array_elements(r.metadata->'shadow_areas') AS sa
                WHERE r.type = 'business_rule'
                  AND jsonb_typeof(r.metadata->'shadow_areas') = 'array'
                  AND (CAST(:project AS int) IS NULL OR r.project_id = :project){$scopeSql}
                ORDER BY CASE sa->>'severity' WHEN 'blocker' THEN 0 WHEN 'major' THEN 1 ELSE 2 END, r.external_id
                LIMIT $limit
                SQL,
            ['project' => $project?->getId()] + (null === $slugs ? [] : ['slugs' => $slugs]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );
    }

    /**
     * Most recently added business documents with their linked rule count.
     *
     * @return list<array{id: int, label: string, format: string, created_at: string, rules_count: int}>
     */
    public function recentDocuments(?Project $project, int $limit = 6): array
    {
        $slugs = null === $project ? $this->projectScope->slugs() : null;
        if ([] === $slugs) {
            return [];
        }
        $scopeSql = null === $slugs ? '' : ' AND EXISTS (SELECT 1 FROM node_project np2 JOIN project p2 ON p2.id = np2.project_id WHERE np2.node_id = d.id AND p2.slug IN (:slugs))';

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'rules_count' => (int) $row['rules_count']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT d.id, d.label, COALESCE(d.metadata->>'format', 'md') AS format, d.created_at,
                           (SELECT COUNT(*) FROM edge e JOIN node r ON r.id = e.target_id WHERE e.source_id = d.id AND r.type = 'business_rule' AND e.status = 'active') AS rules_count
                    FROM node d
                    WHERE d.type = 'document'
                      AND (CAST(:project AS int) IS NULL OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id AND np.project_id = :project)){$scopeSql}
                    ORDER BY d.created_at DESC, d.id DESC
                    LIMIT $limit
                    SQL,
                ['project' => $project?->getId()] + (null === $slugs ? [] : ['slugs' => $slugs]),
                null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * @return list<array{created_at: string, tool: string, project: ?string, query: ?string, results: int, memories: int, failed: bool, duration_ms: int, client: ?string, total: int}>
     */
    public function recentCalls(?Project $project, int $limit = 12, int $offset = 0): array
    {
        return array_map(
            static fn (array $row): array => ['results' => (int) $row['results'], 'memories' => (int) $row['memories'], 'failed' => (bool) $row['failed'], 'duration_ms' => (int) $row['duration_ms']] + $row,
            $this->connection->fetchAllAssociative(
                "SELECT c.created_at, c.tool, c.project, c.query, c.results, c.memories, c.failed, c.duration_ms, COALESCE(k.name, c.client) AS client, COUNT(*) OVER () AS total FROM mcp_call c LEFT JOIN api_key k ON k.id = c.api_key_id WHERE (CAST(:slug AS text) IS NULL OR c.project = :slug) ORDER BY c.created_at DESC, c.id DESC LIMIT $limit OFFSET $offset",
                ['slug' => $project?->getSlug()],
            ),
        );
    }

    /**
     * @return list<array{at: string, value: int}>
     */
    private function series(string $table, string $column, string $where, ?Project $project, \DateTimeImmutable $from, bool $hourly, string $weight = '1'): array
    {
        $unit = $hourly ? 'hour' : 'day';

        return array_map(
            static fn (array $row): array => ['at' => $row['at'], 'value' => (int) $row['value']],
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT to_char(b.at, 'YYYY-MM-DD"T"HH24:MI') AS at, COALESCE(SUM(x.weight), 0) AS value
                    FROM generate_series(date_trunc('{$unit}', CAST(:from AS timestamp)), date_trunc('{$unit}', CAST(:to AS timestamp)), INTERVAL '1 {$unit}') AS b(at)
                    LEFT JOIN (SELECT date_trunc('{$unit}', {$column}) AS at, {$weight} AS weight FROM {$table} WHERE {$where} AND {$column} >= date_trunc('{$unit}', CAST(:from AS timestamp))) x ON x.at = b.at
                    GROUP BY b.at ORDER BY b.at
                    SQL,
                ['from' => $from, 'to' => new \DateTimeImmutable()] + (str_contains($where, ':slug') ? ['slug' => $project?->getSlug()] : ['project' => $project?->getId()]),
                ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
            ),
        );
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<array{label: string, value: int}>
     */
    private function counts(string $sql, array $parameters): array
    {
        return array_map(
            static fn (array $row): array => ['label' => $row['label'], 'value' => (int) $row['value']],
            $this->connection->fetchAllAssociative($sql, $parameters, \array_key_exists('from', $parameters) ? self::TIMESTAMPS : []),
        );
    }

    /**
     * @return array{slug: ?string, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable}
     */
    private function parameters(?Project $project, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        return ['slug' => $project?->getSlug(), 'from' => $from, 'to' => $to];
    }
}
