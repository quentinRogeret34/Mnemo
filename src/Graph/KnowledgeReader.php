<?php

namespace App\Graph;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class KnowledgeReader
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{documents: int, memories: int, rules: int, rules_to_review: int, files: int}
     */
    public function counts(Project $project): array
    {
        return array_map('intval', $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT (SELECT COUNT(*) FROM node_project np JOIN node d ON d.id = np.node_id WHERE np.project_id = :project AND d.type = :document) AS documents,
                       (SELECT COUNT(*) FROM node_project np JOIN node m ON m.id = np.node_id WHERE np.project_id = :project AND m.type = :memory AND COALESCE(m.metadata->>'status', 'active') = 'active') AS memories,
                       COUNT(*) FILTER (WHERE type = :rule) AS rules,
                       COUNT(*) FILTER (WHERE type = :rule AND metadata->>'status' = 'to_review') AS rules_to_review,
                       COUNT(*) FILTER (WHERE type = :file) AS files
                FROM node WHERE project_id = :project
                SQL,
            ['project' => $project->getId(), 'document' => NodeType::Document->value, 'memory' => NodeType::Memory->value, 'rule' => NodeType::BusinessRule->value, 'file' => NodeType::CodeFile->value],
        ));
    }

    public function memoryCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM node m WHERE m.type = :memory AND COALESCE(m.metadata->>'status', 'active') = 'active'", ['memory' => NodeType::Memory->value]);
    }

    /**
     * Active memories, or the archived ones (superseded or retracted) when asked.
     *
     * @return list<array{id: int, text: string, type: string, source: ?string, status: string, verdict: ?string, demo: bool, created_at: string, updated_at: string, projects: ?string, links: int}>
     */
    public function memories(?Project $project = null, ?string $type = null, string $query = '', bool $archived = false): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'demo' => (bool) $row['demo'], 'links' => (int) $row['links']] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT m.id, m.label AS text, m.metadata->>'type' AS type, m.metadata->>'source' AS source,
                           COALESCE(m.metadata->>'status', 'active') AS status, m.metadata->'verification'->>'verdict' AS verdict,
                           COALESCE((m.metadata->>'demo')::boolean, false) AS demo, m.created_at, m.updated_at,
                           (SELECT string_agg(p.name, ', ' ORDER BY p.name) FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = m.id) AS projects,
                           (SELECT COUNT(*) FROM edge e WHERE e.source_id = m.id AND e.relation = 'about' AND e.status = :active) AS links
                    FROM node m
                    WHERE m.type = :memory
                      AND (COALESCE(m.metadata->>'status', 'active') = 'active') <> :archived
                      AND (CAST(:project AS int) IS NULL OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project))
                      AND (CAST(:type AS text) IS NULL OR m.metadata->>'type' = :type)
                      AND (:query = '' OR m.label ILIKE :like)
                    ORDER BY m.updated_at DESC, m.id DESC
                    SQL,
                [
                    'memory' => NodeType::Memory->value,
                    'active' => EdgeStatus::Active->value,
                    'project' => $project?->getId(),
                    'type' => $type,
                    'query' => trim($query),
                    'like' => '%'.addcslashes(trim($query), '\\%_').'%',
                    'archived' => $archived,
                ],
                ['archived' => ParameterType::BOOLEAN],
            ),
        );
    }

    /**
     * @return list<array{edge_id: int, id: int, type: string, label: string, path: ?string, code: string, format: ?string, project_slug: ?string, project_name: ?string}>
     */
    public function memoryLinks(Node $memory): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT e.id AS edge_id, t.id, t.type, t.label, t.path, t.external_id AS code, t.metadata->>'format' AS format,
                       p.slug AS project_slug, p.name AS project_name
                FROM edge e
                JOIN node t ON t.id = e.target_id
                LEFT JOIN project p ON p.id = t.project_id
                WHERE e.source_id = :memory AND e.relation = 'about' AND e.status = :active
                ORDER BY t.type, t.label
                SQL,
            ['memory' => $memory->getId(), 'active' => EdgeStatus::Active->value],
        );
    }

    /**
     * Code files and rules of the memory's projects, and any document, matching the query and not linked yet.
     *
     * @return list<array{id: int, type: string, label: string, path: ?string, code: string, project_name: ?string}>
     */
    public function memoryLinkables(Node $memory, string $query, int $limit = 10): array
    {
        if ('' === trim($query)) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT n.id, n.type, n.label, n.path, n.external_id AS code, p.name AS project_name
                FROM node n
                LEFT JOIN project p ON p.id = n.project_id
                WHERE (
                        (n.type IN (:file, :rule) AND EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = :memory AND np.project_id = n.project_id))
                        OR n.type = :document
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
            ],
        );
    }

    /**
     * Active memories linked to a code file, document or rule, with their decision record and verification verdict.
     *
     * @return list<array{id: int, text: string, type: string, demo: bool, verdict: ?string, adr: ?array<string, string>}>
     */
    public function memoriesAbout(int $nodeId): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'demo' => (bool) $row['demo'], 'adr' => null === $row['adr'] ? null : json_decode($row['adr'], true)] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT m.id, m.label AS text, m.metadata->>'type' AS type, COALESCE((m.metadata->>'demo')::boolean, false) AS demo,
                           m.metadata->'verification'->>'verdict' AS verdict, m.metadata->'adr' AS adr
                    FROM edge e JOIN node m ON m.id = e.source_id
                    WHERE e.target_id = :node AND e.relation = 'about' AND e.status = :active AND m.type = :memory
                      AND COALESCE(m.metadata->>'status', 'active') = 'active'
                    ORDER BY m.updated_at DESC
                    SQL,
                ['node' => $nodeId, 'active' => EdgeStatus::Active->value, 'memory' => NodeType::Memory->value],
            ),
        );
    }

    /**
     * Documents attached to a project, or the documents of a folder when no project is given.
     *
     * @return list<array{id: int, title: string, filename: string, format: string, size: int, demo: bool, created_at: string, rules: int, folder: ?string, projects: ?string, code_links: int}>
     */
    public function documents(?Project $project = null, ?int $folderId = null): array
    {
        $scope = null !== $project
            ? 'EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id AND np.project_id = :project)'
            : (null === $folderId ? 'd.folder_id IS NULL' : 'd.folder_id = :folder');

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'size' => (int) $row['size'], 'demo' => (bool) $row['demo'], 'rules' => (int) $row['rules'], 'code_links' => (int) $row['code_links']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT d.id, d.label AS title, d.path AS filename, d.metadata->>'format' AS format, (d.metadata->>'size')::int AS size,
                           COALESCE((d.metadata->>'demo')::boolean, false) AS demo, d.created_at, f.name AS folder,
                           (SELECT string_agg(p.name, ', ' ORDER BY p.name) FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = d.id) AS projects,
                           (SELECT COUNT(*) FROM edge e JOIN node r ON r.id = e.target_id
                            WHERE e.source_id = d.id AND r.type = :rule AND e.status = :active) AS rules,
                           (SELECT COUNT(*) FROM edge e WHERE e.target_id = d.id AND e.relation = 'documented_by' AND e.status = :active) AS code_links
                    FROM node d
                    LEFT JOIN folder f ON f.id = d.folder_id
                    WHERE d.type = :document AND $scope
                    ORDER BY d.label
                    SQL,
                ['document' => NodeType::Document->value, 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value]
                    + (null !== $project ? ['project' => $project->getId()] : [])
                    + (null === $project && null !== $folderId ? ['folder' => $folderId] : []),
            ),
        );
    }

    /**
     * Every folder in tree order (parents before children, siblings by name).
     *
     * @return list<array{id: int, name: string, parent_id: ?int, depth: int, documents: int}>
     */
    public function folderTree(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'parent_id' => null === $row['parent_id'] ? null : (int) $row['parent_id'], 'depth' => (int) $row['depth'], 'documents' => (int) $row['documents']] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    WITH RECURSIVE tree AS (
                        SELECT id, name, parent_id, 0 AS depth, ARRAY[lower(name), id::text] AS sort_key FROM folder WHERE parent_id IS NULL
                        UNION ALL
                        SELECT f.id, f.name, f.parent_id, t.depth + 1, t.sort_key || ARRAY[lower(f.name), f.id::text]
                        FROM folder f JOIN tree t ON f.parent_id = t.id
                    )
                    SELECT t.id, t.name, t.parent_id, t.depth,
                           (SELECT COUNT(*) FROM node d WHERE d.folder_id = t.id AND d.type = :document) AS documents
                    FROM tree t
                    ORDER BY t.sort_key
                    SQL,
                ['document' => NodeType::Document->value],
            ),
        );
    }

    /**
     * Code files documented by this document (manual links), grouped by project in the query order.
     *
     * @return list<array{edge_id: int, path: string, project_slug: string, project_name: string, confidence: string, origin: string}>
     */
    public function documentCodeLinks(Node $document): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT e.id AS edge_id, c.path, p.slug AS project_slug, p.name AS project_name, e.confidence, e.origin
                FROM edge e
                JOIN node c ON c.id = e.source_id
                JOIN project p ON p.id = c.project_id
                WHERE e.target_id = :document AND e.relation = 'documented_by' AND e.status = :active
                ORDER BY p.name, c.path
                SQL,
            ['document' => $document->getId(), 'active' => EdgeStatus::Active->value],
        );
    }

    /**
     * Code files of the document's projects matching the query and not linked yet.
     *
     * @return list<array{id: int, path: string, project_name: string}>
     */
    public function linkableFiles(Node $document, string $query, int $limit = 8): array
    {
        if ('' === trim($query)) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT c.id, c.path, p.name AS project_name
                FROM node c
                JOIN project p ON p.id = c.project_id
                JOIN node_project np ON np.project_id = c.project_id AND np.node_id = :document
                WHERE c.type = :file AND c.path ILIKE :like
                  AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = c.id AND e.target_id = :document AND e.relation = 'documented_by' AND e.status = :active)
                ORDER BY length(c.path), c.path
                LIMIT $limit
                SQL,
            ['document' => $document->getId(), 'file' => NodeType::CodeFile->value, 'active' => EdgeStatus::Active->value, 'like' => '%'.addcslashes(trim($query), '\\%_').'%'],
        );
    }

    /**
     * @return list<array{id: int, title: string, format: string, confidence: string, origin: string}>
     */
    public function documentsForFile(Project $project, string $path): array
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

    /**
     * @return list<array{code: string, title: string, statement: ?string, status: ?string, confidence: string, origin: string, project_slug: string}>
     */
    public function rulesDefinedBy(Node $document): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT r.external_id AS code, r.label AS title, r.metadata->>'statement' AS statement, r.metadata->>'status' AS status,
                       e.confidence, e.origin, p.slug AS project_slug
                FROM edge e JOIN node r ON r.id = e.target_id JOIN project p ON p.id = r.project_id
                WHERE e.source_id = :document AND r.type = :rule AND e.status = :active
                ORDER BY r.external_id
                SQL,
            ['document' => $document->getId(), 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value],
        );
    }

    /**
     * @return list<array{code: string, title: string, statement: ?string, status: ?string, demo: bool, documents: int, files: int, verified_files: int, shadow_areas: int}>
     */
    public function rules(Project $project, ?string $status = null): array
    {
        return array_map(
            static fn (array $row): array => ['demo' => (bool) $row['demo'], 'documents' => (int) $row['documents'], 'files' => (int) $row['files'], 'verified_files' => (int) $row['verified_files'], 'shadow_areas' => (int) $row['shadow_areas']] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT r.external_id AS code, r.label AS title, r.metadata->>'statement' AS statement, r.metadata->>'status' AS status,
                           COALESCE((r.metadata->>'demo')::boolean, false) AS demo,
                           COALESCE(json_array_length(r.metadata->'shadow_areas'), 0) AS shadow_areas,
                           (SELECT COUNT(*) FROM edge e WHERE e.target_id = r.id AND e.relation = 'defines' AND e.status = :active) AS documents,
                           (SELECT COUNT(*) FROM edge e WHERE e.source_id = r.id AND e.relation = 'implemented_by' AND e.status = :active) AS files,
                           (SELECT COUNT(*) FROM edge e WHERE e.source_id = r.id AND e.relation = 'implemented_by' AND e.status = :active AND e.confidence = 'VERIFIED') AS verified_files
                    FROM node r
                    WHERE r.project_id = :project AND r.type = :rule AND (CAST(:status AS text) IS NULL OR r.metadata->>'status' = :status)
                    ORDER BY r.external_id
                    SQL,
                ['project' => $project->getId(), 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value, 'status' => $status],
            ),
        );
    }

    /**
     * @return list<array{id: int, title: string, filename: string, format: string, confidence: string, origin: string}>
     */
    public function ruleSources(Node $rule): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT d.id, d.label AS title, d.path AS filename, d.metadata->>'format' AS format, e.confidence, e.origin
                FROM edge e JOIN node d ON d.id = e.source_id
                WHERE e.target_id = :rule AND d.type = :document AND e.status = :active
                ORDER BY d.label
                SQL,
            ['rule' => $rule->getId(), 'document' => NodeType::Document->value, 'active' => EdgeStatus::Active->value],
        );
    }

    /**
     * @return list<array{path: string, label: string, type: string, confidence: string, origin: string}>
     */
    public function ruleImplementations(Node $rule): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT c.path, c.label, c.type, e.confidence, e.origin
                FROM edge e JOIN node c ON c.id = e.target_id
                WHERE e.source_id = :rule AND e.relation = 'implemented_by' AND e.status = :active
                ORDER BY CASE e.confidence WHEN 'VERIFIED' THEN 0 WHEN 'EXTRACTED' THEN 1 ELSE 2 END, c.path
                SQL,
            ['rule' => $rule->getId(), 'active' => EdgeStatus::Active->value],
        );
    }

    /**
     * Rules implemented by the file itself or by one of its symbols.
     *
     * @return list<array{code: string, title: string, status: ?string, confidence: string, origin: string}>
     */
    public function rulesForFile(Project $project, string $path): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT ON (r.external_id) r.external_id AS code, r.label AS title, r.metadata->>'status' AS status, e.confidence, e.origin
                FROM edge e
                JOIN node r ON r.id = e.source_id
                JOIN node c ON c.id = e.target_id
                WHERE r.project_id = :project AND r.type = :rule AND e.relation = 'implemented_by' AND e.status = :active AND c.path = :path
                ORDER BY r.external_id, CASE e.confidence WHEN 'VERIFIED' THEN 0 WHEN 'EXTRACTED' THEN 1 ELSE 2 END
                SQL,
            ['project' => $project->getId(), 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value, 'path' => $path],
        );
    }

    /**
     * Keyword search across memories, documents, business rules and code (file paths and symbol names).
     * Code and rules are owned by a project; memories and documents are matched through their attached projects.
     *
     * @param list<string> $types node types to search, among memory, document, business_rule, code_file
     *
     * @return list<array{id: int, type: string, label: string, path: ?string, code: string, project_slug: ?string, snippet: ?string}>
     */
    public function search(string $query, ?Project $project, array $types, int $limit): array
    {
        if ('' === trim($query) || [] === $types) {
            return [];
        }

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
                      AND (n.type <> :memory OR COALESCE(n.metadata->>'status', 'active') = 'active')
                      AND (
                          n.label ILIKE :like OR n.path ILIKE :like OR s.id IS NOT NULL
                          OR (n.type = :rule AND (n.external_id ILIKE :like OR n.metadata->>'statement' ILIKE :like))
                      )
                      AND (
                          CAST(:project AS int) IS NULL
                          OR n.project_id = :project
                          OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project)
                      )
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
                ],
                ['types' => ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * Nodes sharing words with the text, best first: a keyword stand-in for semantic search until vectors exist.
     * Only active memories are returned; memories without any project are shared by all projects.
     *
     * @param list<string> $types   node types among memory, document, business_rule
     * @param list<int>    $exclude node ids to leave out
     *
     * @return list<array{id: int, type: string, label: string, code: string, path: ?string, statement: ?string}>
     */
    public function related(string $text, Project $project, array $types, int $limit, array $exclude = []): array
    {
        preg_match_all('/[\p{L}\p{N}_]{4,}/u', mb_strtolower($text), $words);
        // Prefixes stand in for stemming in any language: "remises" and "remise" both match "remise:*".
        $words = \array_slice(array_values(array_unique(array_map(
            static fn (string $word): string => mb_substr($word, 0, max(4, min(6, mb_strlen($word) - 1))).':*',
            $words[0],
        ))), 0, 40);
        if ([] === $words || [] === $types) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT n.id, n.type, n.label, n.external_id AS code, n.path, n.metadata->>'statement' AS statement
                    FROM node n, to_tsquery('simple', :query) q,
                         to_tsvector('simple', n.label || ' ' || COALESCE(n.metadata->>'statement', '') || ' ' || COALESCE(n.metadata->'adr'->>'decision', '')) v
                    WHERE n.type IN (:types) AND v @@ q AND n.id NOT IN (:exclude)
                      AND (n.type <> :memory OR COALESCE(n.metadata->>'status', 'active') = 'active')
                      AND (
                          n.project_id = :project
                          OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project)
                          OR (n.type = :memory AND NOT EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id))
                      )
                    ORDER BY ts_rank(v, q) DESC, n.id
                    LIMIT $limit
                    SQL,
                ['query' => implode(' | ', $words), 'types' => $types, 'exclude' => [0, ...$exclude], 'memory' => NodeType::Memory->value, 'project' => $project->getId()],
                ['types' => ArrayParameterType::STRING, 'exclude' => ArrayParameterType::INTEGER],
            ),
        );
    }

    /**
     * Memories this one replaces, and the ones that replace it ("supersedes" edges).
     *
     * @return array{replaces: list<array{id: int, text: string, status: string}>, replaced_by: list<array{id: int, text: string, status: string}>}
     */
    public function supersession(Node $memory): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT CASE WHEN e.source_id = :memory THEN 'replaces' ELSE 'replaced_by' END AS direction,
                       m.id, m.label AS text, COALESCE(m.metadata->>'status', 'active') AS status
                FROM edge e JOIN node m ON m.id = CASE WHEN e.source_id = :memory THEN e.target_id ELSE e.source_id END
                WHERE (e.source_id = :memory OR e.target_id = :memory) AND e.relation = 'supersedes' AND e.status = :active
                ORDER BY m.created_at
                SQL,
            ['memory' => $memory->getId(), 'active' => EdgeStatus::Active->value],
        );
        $result = ['replaces' => [], 'replaced_by' => []];
        foreach ($rows as $row) {
            $result[$row['direction']][] = ['id' => (int) $row['id'], 'text' => $row['text'], 'status' => $row['status']];
        }

        return $result;
    }

    public function pendingProposals(?Project $project = null): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM proposal WHERE status = :pending AND (CAST(:project AS int) IS NULL OR project_id = :project)',
            ['pending' => 'pending', 'project' => $project?->getId()],
        );
    }
}
