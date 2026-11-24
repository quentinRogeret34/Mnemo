<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use App\Security\ProjectScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DocumentRepository
{
    public function __construct(
        private Connection $connection,
        private ProjectScope $projectScope,
    ) {
    }

    /**
     * @return list<array{id: int, title: string, filename: string, format: string, size: int, demo: bool, created_at: string, rules: int, folder: ?string, projects: ?string, code_links: int, total: int}>
     */
    public function findPaginated(?Project $project = null, ?int $folderId = null, ?int $limit = null, int $offset = 0): array
    {
        $slugs = $this->projectScope->slugs();
        $projectAggScope = null === $slugs ? '' : ' AND p.slug IN (:slugs)';
        $scope = null !== $project
            ? 'EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id AND np.project_id = :project)'
            : (null === $folderId ? 'd.folder_id IS NULL' : 'd.folder_id = :folder');

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'size' => (int) $row['size'], 'demo' => (bool) $row['demo'], 'rules' => (int) $row['rules'], 'code_links' => (int) $row['code_links']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT d.id, d.label AS title, d.path AS filename, d.metadata->>'format' AS format, (d.metadata->>'size')::int AS size,
                           COALESCE((d.metadata->>'demo')::boolean, false) AS demo, d.created_at, f.name AS folder,
                           (SELECT string_agg(p.name, ', ' ORDER BY p.name) FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = d.id{$projectAggScope}) AS projects,
                           (SELECT COUNT(*) FROM edge e JOIN node r ON r.id = e.target_id
                            WHERE e.source_id = d.id AND r.type = :rule AND e.status = :active) AS rules,
                           (SELECT COUNT(*) FROM edge e WHERE e.target_id = d.id AND e.relation = 'documented_by' AND e.status = :active) AS code_links,
                           COUNT(*) OVER () AS total
                    FROM node d
                    LEFT JOIN folder f ON f.id = d.folder_id
                    WHERE d.type = :document AND $scope
                    ORDER BY d.label, d.id
                    LIMIT :limit OFFSET :offset
                    SQL,
                ['limit' => $limit, 'offset' => $offset, 'document' => NodeType::Document->value, 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value]
                    + (null !== $project ? ['project' => $project->getId()] : [])
                    + (null === $project && null !== $folderId ? ['folder' => $folderId] : [])
                    + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
                null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * @return list<array{id: int, title: string, format: string, folder_id: ?int}>
     */
    public function documentTree(): array
    {
        $slugs = $this->projectScope->slugs();
        $scope = null === $slugs ? '' : ' AND EXISTS (SELECT 1 FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = d.id AND p.slug IN (:slugs))';

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'folder_id' => null === $row['folder_id'] ? null : (int) $row['folder_id']] + $row,
            $this->connection->fetchAllAssociative(
                "SELECT d.id, d.label AS title, d.metadata->>'format' AS format, d.folder_id FROM node d WHERE d.type = :document{$scope} ORDER BY lower(d.label), d.id",
                ['document' => NodeType::Document->value] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
                null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * @return list<array{id: int, name: string, parent_id: ?int, depth: int, documents: int}>
     */
    public function folderTree(): array
    {
        $slugs = $this->projectScope->slugs();
        $docScope = null === $slugs ? '' : ' AND EXISTS (SELECT 1 FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = d.id AND p.slug IN (:slugs))';
        $having = null === $slugs ? '' : ' WHERE documents > 0';

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'parent_id' => null === $row['parent_id'] ? null : (int) $row['parent_id'], 'depth' => (int) $row['depth'], 'documents' => (int) $row['documents']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    WITH RECURSIVE tree AS (
                        SELECT id, name, parent_id, 0 AS depth, ARRAY[lower(name), id::text] AS sort_key, ARRAY[id] AS path FROM folder WHERE parent_id IS NULL
                        UNION ALL
                        SELECT f.id, f.name, f.parent_id, t.depth + 1, t.sort_key || ARRAY[lower(f.name), f.id::text], t.path || f.id
                        FROM folder f JOIN tree t ON f.parent_id = t.id
                        WHERE NOT f.id = ANY(t.path)
                    ),
                    counted AS (
                        SELECT t.id, t.name, t.parent_id, t.depth, t.sort_key,
                               (SELECT COUNT(*) FROM node d JOIN tree s ON s.id = d.folder_id WHERE t.id = ANY(s.path) AND d.type = :document{$docScope}) AS documents
                        FROM tree t
                    )
                    SELECT id, name, parent_id, depth, documents
                    FROM counted{$having}
                    ORDER BY sort_key
                    SQL,
                ['document' => NodeType::Document->value] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
                null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * @return list<array{edge_id: int, path: string, project_slug: string, project_name: string, confidence: string, origin: string}>
     */
    public function codeLinks(Node $document): array
    {
        $slugs = $this->projectScope->slugs();
        $scope = null === $slugs ? '' : ' AND p.slug IN (:slugs)';

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT e.id AS edge_id, c.path, p.slug AS project_slug, p.name AS project_name, e.confidence, e.origin
                FROM edge e
                JOIN node c ON c.id = e.source_id
                JOIN project p ON p.id = c.project_id
                WHERE e.target_id = :document AND e.relation = 'documented_by' AND e.status = :active{$scope}
                ORDER BY p.name, c.path
                SQL,
            ['document' => $document->getId(), 'active' => EdgeStatus::Active->value] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );
    }

    /**
     * @return list<array{id: int, path: string, project_name: string}>
     */
    public function linkableFiles(Node $document, string $query, int $limit = 8): array
    {
        if ('' === trim($query)) {
            return [];
        }
        $slugs = $this->projectScope->slugs();
        $scope = null === $slugs ? '' : ' AND p.slug IN (:slugs)';

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT c.id, c.path, p.name AS project_name
                FROM node c
                JOIN project p ON p.id = c.project_id
                JOIN node_project np ON np.project_id = c.project_id AND np.node_id = :document
                WHERE c.type = :file AND c.path ILIKE :like{$scope}
                  AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = c.id AND e.target_id = :document AND e.relation = 'documented_by' AND e.status = :active)
                ORDER BY length(c.path), c.path
                LIMIT $limit
                SQL,
            ['document' => $document->getId(), 'file' => NodeType::CodeFile->value, 'active' => EdgeStatus::Active->value, 'like' => '%'.addcslashes(trim($query), '\\%_').'%'] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );
    }

    /**
     * @return list<array{id: int, title: string, format: string, confidence: string, origin: string}>
     */
    public function forFile(Project $project, string $path): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT d.id, d.label AS title, d.metadata->>'format' AS format, e.confidence, e.origin
                FROM edge e
                JOIN node c ON c.id = e.source_id
                JOIN node d ON d.id = e.target_id
                WHERE c.project_id = :project AND c.type = :file AND c.path = :path AND e.relation = 'documented_by' AND e.status = :active
                ORDER BY d.label
                SQL,
            ['project' => $project->getId(), 'file' => NodeType::CodeFile->value, 'path' => $path, 'active' => EdgeStatus::Active->value],
        );
    }
}
