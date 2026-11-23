<?php

namespace App\Review;

use App\Entity\Project;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use App\Graph\GraphReader;
use App\Security\MemoryAccess;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What the graph knows about the files of a pull request. The answers are published on the forge, so only the
 * memories shared with the whole project are read, never the private or group ones.
 */
final class ReviewKnowledge
{
    private const HISTORY_FILES = 10;
    private const PROJECT_MEMORIES = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly MemoryAccess $memoryAccess,
        private readonly GraphReader $graphReader,
    ) {
    }

    /**
     * Rules implemented by one of the files or of their symbols.
     *
     * @param list<string> $paths
     *
     * @return list<array{code: string, title: string, statement: ?string, status: ?string, shadow_areas: list<array{gap: string, question: string, severity: string}>, paths: list<string>}>
     */
    public function rules(Project $project, array $paths): array
    {
        if ([] === $paths) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['shadow_areas' => json_decode($row['shadow_areas'], true), 'paths' => json_decode($row['paths'], true)] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT r.external_id AS code, r.label AS title, r.metadata->>'statement' AS statement, r.metadata->>'status' AS status,
                           COALESCE(r.metadata->'shadow_areas', '[]') AS shadow_areas,
                           json_agg(DISTINCT c.path ORDER BY c.path) AS paths
                    FROM edge e
                    JOIN node r ON r.id = e.source_id
                    JOIN node c ON c.id = e.target_id
                    WHERE r.project_id = :project AND r.type = :rule AND e.relation = 'implemented_by' AND e.status = :active AND c.path IN (:paths)
                    GROUP BY r.id
                    ORDER BY r.external_id
                    SQL,
                ['project' => $project->getId(), 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value, 'paths' => $paths],
                ['paths' => ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * Memories linked to one of the files or rules, each with what it is about (paths and rule codes).
     *
     * @param list<string> $paths
     * @param list<string> $ruleCodes
     *
     * @return list<array{id: int, text: string, type: string, about: list<string>}>
     */
    public function linkedMemories(Project $project, array $paths, array $ruleCodes): array
    {
        if ([] === $paths && [] === $ruleCodes) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'about' => json_decode($row['about'], true)] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT m.id, m.label AS text, m.metadata->>'type' AS type, json_agg(DISTINCT COALESCE(t.path, t.external_id)) AS about
                    FROM edge e
                    JOIN node m ON m.id = e.source_id
                    JOIN node t ON t.id = e.target_id
                    WHERE e.relation = 'about' AND e.status = :active AND m.type = :memory AND t.project_id = :project
                      AND (t.path IN (:paths) OR (t.type = :rule AND t.external_id IN (:rules)))
                      AND COALESCE(m.metadata->>'status', 'active') = 'active' AND {$this->memoryAccess->sharedSql('m')}
                    GROUP BY m.id
                    ORDER BY m.updated_at DESC
                    SQL,
                ['project' => $project->getId(), 'memory' => NodeType::Memory->value, 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value, 'paths' => $paths, 'rules' => $ruleCodes],
                ['paths' => ArrayParameterType::STRING, 'rules' => ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * The latest decisions and conventions of the project, whatever they are linked to.
     *
     * @return list<array{id: int, text: string, type: string}>
     */
    public function projectMemories(Project $project): array
    {
        $limit = self::PROJECT_MEMORIES;

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT m.id, m.label AS text, m.metadata->>'type' AS type
                    FROM node m
                    WHERE m.type = :memory AND m.metadata->>'type' IN ('decision', 'convention')
                      AND COALESCE(m.metadata->>'status', 'active') = 'active' AND {$this->memoryAccess->sharedSql('m')}
                      AND EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project)
                    ORDER BY m.updated_at DESC
                    LIMIT $limit
                    SQL,
                ['project' => $project->getId(), 'memory' => NodeType::Memory->value],
            ),
        );
    }

    /**
     * Latest commits of the first files, with their change requests.
     *
     * @param list<string> $paths
     *
     * @return array<string, list<array{sha: string, label: string, author: ?string, date: ?string, url: ?string, tickets: list<array{ref: string, title: string, url: ?string}>}>>
     */
    public function history(Project $project, array $paths): array
    {
        $history = [];
        foreach (\array_slice($paths, 0, self::HISTORY_FILES) as $path) {
            $history[$path] = $this->graphReader->fileHistory($project, $path, 3);
        }

        return array_filter($history);
    }
}
