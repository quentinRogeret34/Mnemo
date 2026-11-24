<?php

namespace App\Repository;

use App\Entity\Project;
use App\Enum\NodeType;
use App\Security\MemoryAccess;
use App\Security\ProjectScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class KnowledgeSearchRepository
{
    public function __construct(
        private Connection $connection,
        private MemoryAccess $memoryAccess,
        private ProjectScope $projectScope,
    ) {
    }

    /**
     * @param list<int> $projectIds
     *
     * @return array<int, array{documents: int, memories: int, rules: int, rules_to_review: int, files: int}>
     */
    public function countsByProject(array $projectIds): array
    {
        $empty = ['documents' => 0, 'memories' => 0, 'rules' => 0, 'rules_to_review' => 0, 'files' => 0];
        if ([] === $projectIds) {
            return [];
        }
        $result = array_fill_keys($projectIds, $empty);

        $owned = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT project_id,
                       COUNT(*) FILTER (WHERE type = :rule) AS rules,
                       COUNT(*) FILTER (WHERE type = :rule AND metadata->>'status' = 'to_review') AS rules_to_review,
                       COUNT(*) FILTER (WHERE type = :file) AS files
                FROM node
                WHERE project_id IN (:projects)
                GROUP BY project_id
                SQL,
            ['projects' => $projectIds, 'rule' => NodeType::BusinessRule->value, 'file' => NodeType::CodeFile->value],
            ['projects' => ArrayParameterType::INTEGER],
        );
        foreach ($owned as $row) {
            $id = (int) $row['project_id'];
            $result[$id]['rules'] = (int) $row['rules'];
            $result[$id]['rules_to_review'] = (int) $row['rules_to_review'];
            $result[$id]['files'] = (int) $row['files'];
        }

        $active = NodeSql::activeMemory('n');
        $attached = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT np.project_id,
                       COUNT(*) FILTER (WHERE n.type = :document) AS documents,
                       COUNT(*) FILTER (WHERE n.type = :memory AND {$active} AND {$this->memoryAccess->sql('n')}) AS memories
                FROM node_project np
                JOIN node n ON n.id = np.node_id
                WHERE np.project_id IN (:projects) AND n.type IN (:document, :memory)
                GROUP BY np.project_id
                SQL,
            ['projects' => $projectIds, 'document' => NodeType::Document->value, 'memory' => NodeType::Memory->value],
            ['projects' => ArrayParameterType::INTEGER],
        );
        foreach ($attached as $row) {
            $id = (int) $row['project_id'];
            $result[$id]['documents'] = (int) $row['documents'];
            $result[$id]['memories'] = (int) $row['memories'];
        }

        return $result;
    }

    /**
     * @param list<string> $types
     *
     * @return list<array{id: int, type: string, label: string, path: ?string, code: string, project_slug: ?string, snippet: ?string}>
     */
    public function search(string $query, ?Project $project, array $types, int $limit): array
    {
        if ('' === trim($query) || [] === $types) {
            return [];
        }

        $slugs = null === $project ? $this->projectScope->slugs() : null;
        if ([] === $slugs) {
            return [];
        }
        $scopeSql = null === $slugs
            ? ''
            : ' AND (p.slug IN (:slugs) OR EXISTS (SELECT 1 FROM node_project np JOIN project p2 ON p2.id = np.project_id WHERE np.node_id = n.id AND p2.slug IN (:slugs)))';
        $active = NodeSql::activeMemory('n');

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT id, type, label, path, code, project_slug, snippet FROM (
                    SELECT DISTINCT ON (n.id) n.id, n.type, n.label, n.path, n.external_id AS code, p.slug AS project_slug,
                           CASE WHEN n.type = :rule THEN n.metadata->>'statement' WHEN n.type = :memory THEN n.metadata->'adr'->>'decision' WHEN s.id IS NOT NULL THEN 'symbol: ' || s.label END AS snippet,
                           CASE n.type WHEN :memory THEN 0 WHEN :rule THEN 1 WHEN :document THEN 2 ELSE 3 END AS rank
                    FROM node n
                    LEFT JOIN project p ON p.id = n.project_id
                    LEFT JOIN node s ON n.type = :file AND s.project_id = n.project_id AND s.path = n.path AND s.type = :symbol AND s.label ILIKE :like
                    WHERE n.type IN (:types)
                      AND (n.type <> :memory OR ({$active} AND {$this->memoryAccess->sql('n')}))
                      AND (
                          n.label ILIKE :like OR n.path ILIKE :like OR s.id IS NOT NULL
                          OR (n.type = :rule AND (n.external_id ILIKE :like OR n.metadata->>'statement' ILIKE :like))
                      )
                      AND (
                          CAST(:project AS int) IS NULL
                          OR n.project_id = :project
                          OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project)
                      ){$scopeSql}
                    ORDER BY n.id
                    ) matches
                    ORDER BY rank, length(COALESCE(path, label)), label
                    LIMIT $limit
                    SQL,
                [
                    'types' => $types,
                    'project' => $project?->getId(),
                    'like' => '%'.addcslashes(trim($query), '\\%_').'%',
                    'memory' => NodeType::Memory->value,
                    'rule' => NodeType::BusinessRule->value,
                    'document' => NodeType::Document->value,
                    'file' => NodeType::CodeFile->value,
                    'symbol' => NodeType::Symbol->value,
                ] + (null === $slugs ? [] : ['slugs' => $slugs]),
                ['types' => ArrayParameterType::STRING] + (null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING]),
            ),
        );
    }

    /**
     * @param list<string> $types
     * @param list<int>    $exclude
     *
     * @return list<array{id: int, type: string, label: string, code: string, path: ?string, statement: ?string}>
     */
    public function related(string $text, Project $project, array $types, int $limit, array $exclude = []): array
    {
        preg_match_all('/[\p{L}\p{N}_]{4,}/u', mb_strtolower($text), $words);
        $words = \array_slice(array_values(array_unique(array_map(
            static fn (string $word): string => mb_substr($word, 0, max(4, min(6, mb_strlen($word) - 1))).':*',
            $words[0],
        ))), 0, 40);
        if ([] === $words || [] === $types) {
            return [];
        }

        $workspaceClause = null === $this->projectScope->slugs()
            ? ' OR (n.type = :memory AND NOT EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id))'
            : '';
        $active = NodeSql::activeMemory('n');

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT n.id, n.type, n.label, n.external_id AS code, n.path, n.metadata->>'statement' AS statement
                    FROM node n, to_tsquery('simple', :query) q,
                         to_tsvector('simple', n.label || ' ' || COALESCE(n.metadata->>'statement', '') || ' ' || COALESCE(n.metadata->'adr'->>'decision', '')) v
                    WHERE n.type IN (:types) AND v @@ q AND n.id NOT IN (:exclude)
                      AND (n.type <> :memory OR ({$active} AND {$this->memoryAccess->sql('n')}))
                      AND (
                          n.project_id = :project
                          OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project){$workspaceClause}
                      )
                    ORDER BY ts_rank(v, q) DESC, n.id
                    LIMIT $limit
                    SQL,
                ['query' => implode(' | ', $words), 'types' => $types, 'exclude' => [0, ...$exclude], 'memory' => NodeType::Memory->value, 'project' => $project->getId()],
                ['types' => ArrayParameterType::STRING, 'exclude' => ArrayParameterType::INTEGER],
            ),
        );
    }
}
