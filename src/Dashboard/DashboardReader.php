<?php

namespace App\Dashboard;

use App\Entity\Project;
use App\Enum\NodeType;
use App\Security\MemoryAccess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Figures of the dashboard: memories stored, and what agents asked the MCP server.
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
                       (SELECT COALESCE(SUM(c.memories), 0) FROM mcp_call c WHERE {$scope}) AS recalled,
                       (SELECT COUNT(*) FROM mcp_call c WHERE {$scope} AND c.tool IN ('search', 'file_context', 'get_document')) AS lookups,
                       (SELECT COUNT(*) FROM mcp_call c WHERE {$scope}) AS requests,
                       (SELECT COUNT(*) FROM mcp_call c WHERE {$scope} AND c.failed) AS failed,
                       (SELECT COUNT(*) FROM proposal p WHERE p.status = 'pending' AND (CAST(:project AS int) IS NULL OR p.project_id = :project)) AS pending
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
                    LEFT JOIN (SELECT date_trunc('{$unit}', {$column}) AS at, {$weight} AS weight FROM {$table} WHERE {$where}) x ON x.at = b.at
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
