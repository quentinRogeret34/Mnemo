<?php

namespace App\Repository;

use App\Entity\Project;
use App\Enum\EdgeStatus;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Security\MemoryAccess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Encapsulates SQL queries feeding the plain-language business briefing for a project over a time window.
 */
final readonly class BriefingRepository
{
    public function __construct(
        private Connection $connection,
        private MemoryAccess $memoryAccess,
    ) {
    }

    /**
     * @return list<array{code: string, title: string, status: string, statement: string}>
     */
    public function rulesTouchedSince(Project $project, \DateTimeImmutable $since): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT r.external_id AS code, r.label AS title,
                       COALESCE(r.metadata->>'status', 'to_review') AS status,
                       COALESCE(r.metadata->>'statement', '') AS statement
                FROM node r
                WHERE r.project_id = :project AND r.type = :rule
                  AND (
                      r.updated_at >= :since
                      OR EXISTS (
                          SELECT 1
                          FROM edge impl
                          JOIN edge touch ON touch.target_id = impl.target_id AND touch.relation = 'touches' AND touch.status = :active
                          JOIN node c ON c.id = touch.source_id AND c.type = :commit AND c.created_at >= :since
                          WHERE impl.source_id = r.id AND impl.relation = 'implemented_by' AND impl.status = :active
                      )
                  )
                ORDER BY r.external_id
                LIMIT 25
                SQL,
            [
                'project' => $project->getId(),
                'rule' => NodeType::BusinessRule->value,
                'commit' => NodeType::Commit->value,
                'active' => EdgeStatus::Active->value,
                'since' => $since,
            ],
            ['since' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @return list<array{id: int, text: string, created_at: string}>
     */
    public function decisionsSince(Project $project, \DateTimeImmutable $since): array
    {
        $active = NodeSql::activeMemory('m');

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'text' => (string) $row['text'], 'created_at' => (string) $row['created_at']],
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT m.id, m.label AS text, m.created_at
                    FROM node m
                    JOIN node_project np ON np.node_id = m.id
                    WHERE np.project_id = :project
                      AND m.type = :memory
                      AND m.metadata->>'type' = :decision
                      AND {$active}
                      AND m.created_at >= :since
                      AND {$this->memoryAccess->sql('m')}
                    ORDER BY m.created_at DESC
                    LIMIT 20
                    SQL,
                [
                    'project' => $project->getId(),
                    'memory' => NodeType::Memory->value,
                    'decision' => MemoryType::Decision->value,
                    'since' => $since,
                ],
                ['since' => Types::DATETIME_IMMUTABLE],
            ),
        );
    }

    /**
     * @return list<array{rule_code: string, rule_title: string, question: string, answer: string, resolved_by: ?string, resolved_at: string}>
     */
    public function answeredQuestionsSince(Project $project, \DateTimeImmutable $since): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT r.external_id AS rule_code, r.label AS rule_title,
                       COALESCE(item->>'question', item->>'gap', '') AS question,
                       COALESCE(item->>'answer', '') AS answer,
                       item->>'resolved_by' AS resolved_by,
                       COALESCE(item->>'resolved_at', '') AS resolved_at
                FROM node r
                CROSS JOIN LATERAL jsonb_array_elements(COALESCE(r.metadata->'resolved_shadow_areas', '[]'::jsonb)) AS item
                WHERE r.project_id = :project AND r.type = :rule
                  AND NULLIF(item->>'resolved_at', '')::timestamptz >= :since
                ORDER BY item->>'resolved_at' DESC
                LIMIT 15
                SQL,
            ['project' => $project->getId(), 'rule' => NodeType::BusinessRule->value, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @return list<array{ref: string, label: string, kind: ?string, state: ?string, author: ?string, url: ?string, created_at: string}>
     */
    public function ticketsSince(Project $project, \DateTimeImmutable $since): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT external_id AS ref, label, metadata->>'kind' AS kind, metadata->>'state' AS state,
                       metadata->>'author' AS author, metadata->>'url' AS url, created_at
                FROM node
                WHERE project_id = :project AND type = :ticket AND created_at >= :since
                ORDER BY created_at DESC
                LIMIT 20
                SQL,
            ['project' => $project->getId(), 'ticket' => NodeType::Ticket->value, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function countCommitsSince(Project $project, \DateTimeImmutable $since): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM node WHERE project_id = :project AND type = :commit AND created_at >= :since',
            ['project' => $project->getId(), 'commit' => NodeType::Commit->value, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @return list<array{ref: string, label: string, author: ?string, created_at: string}>
     */
    public function recentCommitsSince(Project $project, \DateTimeImmutable $since): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT external_id AS ref, label, metadata->>'author' AS author, created_at
                FROM node
                WHERE project_id = :project AND type = :commit AND created_at >= :since
                ORDER BY created_at DESC
                LIMIT 15
                SQL,
            ['project' => $project->getId(), 'commit' => NodeType::Commit->value, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );
    }
}
