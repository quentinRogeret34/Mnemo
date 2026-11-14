<?php

namespace App\Dashboard;

use App\Entity\Project;
use App\Security\MemoryAccess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * What entered the graph lately: the daily digest of the team's work.
 */
final class ActivityReader
{
    private const TYPES = "('commit', 'ticket', 'document', 'memory', 'business_rule')";

    public function __construct(
        private readonly Connection $connection,
        private readonly MemoryAccess $memoryAccess,
    ) {
    }

    /**
     * Newest first, grouped by the day the node entered the graph.
     *
     * @return array<string, list<array{id: int, type: string, label: string, ref: string, kind: ?string, author: ?string, url: ?string, project_slug: ?string, created_at: string}>>
     */
    public function feed(?Project $project, \DateTimeImmutable $from, int $limit = 300, int $offset = 0): array
    {
        $types = self::TYPES;
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT n.id, n.type, n.label, n.external_id AS ref, n.metadata->>'kind' AS kind, n.metadata->>'author' AS author,
                       n.metadata->>'url' AS url, p.slug AS project_slug, n.created_at
                FROM node n LEFT JOIN project p ON p.id = n.project_id
                WHERE n.type IN {$types} AND n.created_at >= :from AND (n.type <> 'memory' OR {$this->memoryAccess->sql('n')})
                  AND (
                      CAST(:project AS int) IS NULL OR n.project_id = :project
                      OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project)
                  )
                ORDER BY n.created_at DESC, n.id DESC
                LIMIT $limit OFFSET $offset
                SQL,
            ['project' => $project?->getId(), 'from' => $from],
            ['from' => Types::DATETIME_IMMUTABLE],
        );

        $days = [];
        foreach ($rows as $row) {
            $days[substr($row['created_at'], 0, 10)][] = ['id' => (int) $row['id']] + $row;
        }

        return $days;
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    public function counts(?Project $project, \DateTimeImmutable $from): array
    {
        $types = self::TYPES;

        return array_map(
            static fn (array $row): array => ['label' => $row['label'], 'value' => (int) $row['value']],
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT n.type AS label, COUNT(*) AS value FROM node n
                    WHERE n.type IN {$types} AND n.created_at >= :from AND (n.type <> 'memory' OR {$this->memoryAccess->sql('n')})
                      AND (
                          CAST(:project AS int) IS NULL OR n.project_id = :project
                          OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project)
                      )
                    GROUP BY 1 ORDER BY 2 DESC, 1
                    SQL,
                ['project' => $project?->getId(), 'from' => $from],
                ['from' => Types::DATETIME_IMMUTABLE],
            ),
        );
    }

    /**
     * @return list<array{connector_id: int, connector: string, trigger: string, started_at: string, finished_at: string, report: array<string, mixed>, total: int}>
     */
    public function runs(?Project $project, int $limit = 20, int $offset = 0): array
    {
        return array_map(
            static fn (array $row): array => ['connector_id' => (int) $row['connector_id'], 'report' => json_decode($row['report'], true)] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT c.id AS connector_id, c.name AS connector, r.triggered_by AS trigger, r.started_at, r.finished_at, r.report, COUNT(*) OVER () AS total
                    FROM connector_run r JOIN connector c ON c.id = r.connector_id
                    WHERE CAST(:project AS int) IS NULL OR c.project_id = :project
                    ORDER BY r.started_at DESC, r.id DESC
                    LIMIT $limit OFFSET $offset
                    SQL,
                ['project' => $project?->getId()],
            ),
        );
    }
}
