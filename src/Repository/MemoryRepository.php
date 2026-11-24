<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use App\Security\MemoryAccess;
use App\Security\ProjectScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class MemoryRepository
{
    public function __construct(
        private Connection $connection,
        private MemoryAccess $memoryAccess,
        private ProjectScope $projectScope,
    ) {
    }

    public function countActive(): int
    {
        $active = NodeSql::activeMemory('m');

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM node m WHERE m.type = :memory AND {$active} AND ".$this->memoryAccess->sql('m'),
            ['memory' => NodeType::Memory->value],
        );
    }

    /**
     * @return list<array{id: int, text: string, type: string, source: ?string, status: string, verdict: ?string, demo: bool, created_at: string, updated_at: string, projects: ?string, links: int, total: int}>
     */
    public function findFiltered(?Project $project = null, ?string $type = null, string $query = '', bool $archived = false, ?string $verdict = null, ?string $source = null, ?int $limit = null, int $offset = 0): array
    {
        $slugs = $this->projectScope->slugs();
        $projectAggScope = null === $slugs ? '' : ' AND p.slug IN (:slugs)';
        $active = NodeSql::activeMemory('m');

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'demo' => (bool) $row['demo'], 'links' => (int) $row['links']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT m.id, m.label AS text, m.metadata->>'type' AS type, m.metadata->>'source' AS source,
                           COALESCE(m.metadata->>'status', 'active') AS status, m.metadata->'verification'->>'verdict' AS verdict,
                           COALESCE(m.metadata->>'visibility', 'project') AS visibility,
                           COALESCE((m.metadata->>'demo')::boolean, false) AS demo, m.created_at, m.updated_at,
                           (SELECT string_agg(p.name, ', ' ORDER BY p.name) FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = m.id{$projectAggScope}) AS projects,
                           (SELECT COUNT(*) FROM edge e WHERE e.source_id = m.id AND e.relation = 'about' AND e.status = :active) AS links,
                           COUNT(*) OVER () AS total
                    FROM node m
                    WHERE m.type = :memory AND {$this->memoryAccess->sql('m')}
                      AND ({$active}) <> :archived
                      AND (CAST(:project AS int) IS NULL OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project))
                      AND (CAST(:type AS text) IS NULL OR m.metadata->>'type' = :type)
                      AND (:query = '' OR m.label ILIKE :like)
                      AND (CAST(:verdict AS text) IS NULL OR COALESCE(m.metadata->'verification'->>'verdict', 'none') = :verdict)
                      AND (CAST(:source AS text) IS NULL OR m.metadata->>'source' = :source)
                    ORDER BY m.updated_at DESC, m.id DESC
                    LIMIT :limit OFFSET :offset
                    SQL,
                [
                    'limit' => $limit,
                    'offset' => $offset,
                    'memory' => NodeType::Memory->value,
                    'active' => EdgeStatus::Active->value,
                    'project' => $project?->getId(),
                    'type' => $type,
                    'query' => trim($query),
                    'like' => '%'.addcslashes(trim($query), '\\%_').'%',
                    'archived' => $archived,
                    'verdict' => $verdict,
                    'source' => $source,
                ] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
                ['archived' => ParameterType::BOOLEAN] + (null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING]),
            ),
        );
    }

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        $slugs = $this->projectScope->slugs();
        $scope = null === $slugs ? '' : ' AND EXISTS (SELECT 1 FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = m.id AND p.slug IN (:slugs))';

        return $this->connection->fetchFirstColumn(
            "SELECT DISTINCT m.metadata->>'source' FROM node m WHERE m.type = :memory AND m.metadata->>'source' IS NOT NULL AND {$this->memoryAccess->sql('m')}{$scope} ORDER BY 1",
            ['memory' => NodeType::Memory->value] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );
    }

    /**
     * @return array{memories: list<array{id: int, text: string, type: ?string}>, links: list<array{memory_id: int, id: int, type: string, label: string, path: ?string, code: string, project_slug: ?string}>, projects: list<array{memory_id: int, slug: string, name: string}>}
     */
    public function galaxy(?Project $project, int $limit = 300): array
    {
        $slugs = $this->projectScope->slugs();
        $active = NodeSql::activeMemory('m');
        $memories = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT m.id, m.label AS text, m.metadata->>'type' AS type
                FROM node m
                WHERE m.type = :memory AND {$active} AND {$this->memoryAccess->sql('m')}
                  AND (CAST(:project AS int) IS NULL OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project))
                ORDER BY m.updated_at DESC, m.id DESC
                LIMIT $limit
                SQL,
            ['memory' => NodeType::Memory->value, 'project' => $project?->getId()],
        );
        $ids = [0, ...array_column($memories, 'id')];
        $linkScope = null === $slugs ? '' : ' AND ((t.project_id IS NOT NULL AND p.slug IN (:slugs)) OR (t.project_id IS NULL AND EXISTS (SELECT 1 FROM node_project np JOIN project p2 ON p2.id = np.project_id WHERE np.node_id = t.id AND p2.slug IN (:slugs))))';
        $projectScope = null === $slugs ? '' : ' AND p.slug IN (:slugs)';

        return [
            'memories' => $memories,
            'links' => $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT e.source_id AS memory_id, t.id, t.type, t.label, t.path, t.external_id AS code, p.slug AS project_slug
                    FROM edge e JOIN node t ON t.id = e.target_id LEFT JOIN project p ON p.id = t.project_id
                    WHERE e.relation = 'about' AND e.status = :active AND e.source_id IN (:ids){$linkScope}
                    SQL,
                ['active' => EdgeStatus::Active->value, 'ids' => $ids] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
                ['ids' => ArrayParameterType::INTEGER] + (null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING]),
            ),
            'projects' => $this->connection->fetchAllAssociative(
                "SELECT np.node_id AS memory_id, p.slug, p.name FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id IN (:ids){$projectScope}",
                ['ids' => $ids] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
                ['ids' => ArrayParameterType::INTEGER] + (null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING]),
            ),
        ];
    }

    /**
     * @return list<array{edge_id: int, id: int, type: string, label: string, path: ?string, code: string, format: ?string, project_slug: ?string, project_name: ?string}>
     */
    public function links(Node $memory): array
    {
        $slugs = $this->projectScope->slugs();
        $scope = null === $slugs ? '' : ' AND ((t.project_id IS NOT NULL AND p.slug IN (:slugs)) OR (t.project_id IS NULL AND EXISTS (SELECT 1 FROM node_project np JOIN project p2 ON p2.id = np.project_id WHERE np.node_id = t.id AND p2.slug IN (:slugs))))';

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT e.id AS edge_id, t.id, t.type, t.label, t.path, t.external_id AS code, t.metadata->>'format' AS format,
                       p.slug AS project_slug, p.name AS project_name
                FROM edge e
                JOIN node t ON t.id = e.target_id
                LEFT JOIN project p ON p.id = t.project_id
                WHERE e.source_id = :memory AND e.relation = 'about' AND e.status = :active{$scope}
                ORDER BY t.type, t.label
                SQL,
            ['memory' => $memory->getId(), 'active' => EdgeStatus::Active->value] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );
    }

    /**
     * @return list<array{id: int, type: string, label: string, path: ?string, code: string, project_name: ?string}>
     */
    public function linkables(Node $memory, string $query, int $limit = 10): array
    {
        if ('' === trim($query)) {
            return [];
        }
        $slugs = $this->projectScope->slugs();
        $fileScope = null === $slugs ? '' : ' AND p.slug IN (:slugs)';
        $docScope = null === $slugs ? '' : ' AND EXISTS (SELECT 1 FROM node_project np2 JOIN project p2 ON p2.id = np2.project_id WHERE np2.node_id = n.id AND p2.slug IN (:slugs))';

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT n.id, n.type, n.label, n.path, n.external_id AS code, p.name AS project_name
                FROM node n
                LEFT JOIN project p ON p.id = n.project_id
                WHERE (
                        (n.type IN (:file, :rule) AND EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = :memory AND np.project_id = n.project_id){$fileScope})
                        OR (n.type = :document{$docScope})
                    )
                  AND (n.label ILIKE :like OR n.path ILIKE :like OR (n.type = :rule AND n.external_id ILIKE :like))
                  AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = :memory AND e.target_id = n.id AND e.relation = 'about' AND e.status = :active)
                ORDER BY CASE n.type WHEN :rule THEN 0 WHEN :document THEN 1 ELSE 2 END, length(COALESCE(n.path, n.label)), n.label
                LIMIT $limit
                SQL,
            [
                'memory' => $memory->getId(),
                'file' => NodeType::CodeFile->value,
                'rule' => NodeType::BusinessRule->value,
                'document' => NodeType::Document->value,
                'active' => EdgeStatus::Active->value,
                'like' => '%'.addcslashes(trim($query), '\\%_').'%',
            ] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );
    }

    /**
     * @return list<array{id: int, text: string, type: string, demo: bool, verdict: ?string, adr: ?array<string, string>}>
     */
    public function aboutNode(int $nodeId): array
    {
        $active = NodeSql::activeMemory('m');

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'demo' => (bool) $row['demo'], 'adr' => null === $row['adr'] ? null : json_decode($row['adr'], true)] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT m.id, m.label AS text, m.metadata->>'type' AS type, COALESCE((m.metadata->>'demo')::boolean, false) AS demo,
                           m.metadata->'verification'->>'verdict' AS verdict, m.metadata->'adr' AS adr
                    FROM edge e JOIN node m ON m.id = e.source_id
                    WHERE e.target_id = :node AND e.relation = 'about' AND e.status = :active AND m.type = :memory
                      AND {$active} AND {$this->memoryAccess->sql('m')}
                    ORDER BY m.updated_at DESC
                    SQL,
                ['node' => $nodeId, 'active' => EdgeStatus::Active->value, 'memory' => NodeType::Memory->value],
            ),
        );
    }

    /**
     * @return array{replaces: list<array{id: int, text: string, status: string}>, replaced_by: list<array{id: int, text: string, status: string}>}
     */
    public function supersession(Node $memory): array
    {
        $slugs = $this->projectScope->slugs();
        $scope = null === $slugs ? '' : ' AND EXISTS (SELECT 1 FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = m.id AND p.slug IN (:slugs))';
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT CASE WHEN e.source_id = :memory THEN 'replaces' ELSE 'replaced_by' END AS direction,
                       m.id, m.label AS text, COALESCE(m.metadata->>'status', 'active') AS status
                FROM edge e JOIN node m ON m.id = CASE WHEN e.source_id = :memory THEN e.target_id ELSE e.source_id END
                WHERE (e.source_id = :memory OR e.target_id = :memory) AND e.relation = 'supersedes' AND e.status = :active
                  AND {$this->memoryAccess->sql('m')}{$scope}
                ORDER BY m.created_at
                SQL,
            ['memory' => $memory->getId(), 'active' => EdgeStatus::Active->value] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );
        $result = ['replaces' => [], 'replaced_by' => []];
        foreach ($rows as $row) {
            $result[$row['direction']][] = ['id' => (int) $row['id'], 'text' => $row['text'], 'status' => $row['status']];
        }

        return $result;
    }
}
