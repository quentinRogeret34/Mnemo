<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeStatus;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Security\MemoryAccess;
use App\Security\ProjectScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Encapsulates SQL queries around BusinessRule nodes: listings, links, candidates, open questions, and alignment metrics.
 */
final readonly class RuleRepository
{
    public function __construct(
        private Connection $connection,
        private ProjectScope $projectScope,
        private MemoryAccess $memoryAccess,
    ) {
    }

    /**
     * @return list<array{code: string, title: string, statement: ?string, status: ?string, demo: bool, documents: int, files: int, verified_files: int, shadow_areas: int, total: int}>
     */
    public function rules(Project $project, ?string $status = null, ?int $limit = null, int $offset = 0): array
    {
        return array_map(
            static fn (array $row): array => ['demo' => (bool) $row['demo'], 'documents' => (int) $row['documents'], 'files' => (int) $row['files'], 'verified_files' => (int) $row['verified_files'], 'shadow_areas' => (int) $row['shadow_areas']] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT r.external_id AS code, r.label AS title, r.metadata->>'statement' AS statement, r.metadata->>'status' AS status,
                           COALESCE((r.metadata->>'demo')::boolean, false) AS demo,
                           COALESCE(jsonb_array_length(r.metadata->'shadow_areas'), 0) AS shadow_areas,
                           (SELECT COUNT(*) FROM edge e WHERE e.target_id = r.id AND e.relation = 'defines' AND e.status = :active) AS documents,
                           (SELECT COUNT(*) FROM edge e WHERE e.source_id = r.id AND e.relation = 'implemented_by' AND e.status = :active) AS files,
                           (SELECT COUNT(*) FROM edge e WHERE e.source_id = r.id AND e.relation = 'implemented_by' AND e.status = :active AND e.confidence = 'VERIFIED') AS verified_files,
                           COUNT(*) OVER () AS total
                    FROM node r
                    WHERE r.project_id = :project AND r.type = :rule AND (CAST(:status AS text) IS NULL OR r.metadata->>'status' = :status)
                    ORDER BY r.external_id
                    LIMIT :limit OFFSET :offset
                    SQL,
                ['project' => $project->getId(), 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value, 'status' => $status, 'limit' => $limit, 'offset' => $offset],
            ),
        );
    }

    /**
     * @return list<array{code: string, title: string, statement: ?string, status: ?string, confidence: string, origin: string, project_slug: string}>
     */
    public function definedBy(Node $document): array
    {
        $slugs = $this->projectScope->slugs();
        $scope = null === $slugs ? '' : ' AND p.slug IN (:slugs)';

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT r.external_id AS code, r.label AS title, r.metadata->>'statement' AS statement, r.metadata->>'status' AS status,
                       e.confidence, e.origin, p.slug AS project_slug
                FROM edge e JOIN node r ON r.id = e.target_id JOIN project p ON p.id = r.project_id
                WHERE e.source_id = :document AND r.type = :rule AND e.status = :active{$scope}
                ORDER BY r.external_id
                SQL,
            ['document' => $document->getId(), 'rule' => NodeType::BusinessRule->value, 'active' => EdgeStatus::Active->value] + (null === $slugs ? [] : ['slugs' => ['', ...$slugs]]),
            null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING],
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
     * @return list<array{type: string, ref: string, label: string, author: ?string, date: ?string, url: ?string, state: ?string}>
     */
    public function mentions(Node $rule): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT n.type, n.external_id AS ref, n.label, n.metadata->>'author' AS author, n.metadata->>'date' AS date,
                       n.metadata->>'url' AS url, n.metadata->>'state' AS state
                FROM edge e JOIN node n ON n.id = e.source_id
                WHERE e.target_id = :rule AND e.relation = 'mentions' AND e.status = :active
                ORDER BY n.metadata->>'date' DESC
                LIMIT 30
                SQL,
            ['rule' => $rule->getId(), 'active' => EdgeStatus::Active->value],
        );
    }

    /**
     * @return list<array{code: string, title: string, status: ?string, confidence: string, origin: string}>
     */
    public function forFile(Project $project, string $path): array
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
     * @return list<array{edge_id: int, id: int, path: string, origin: string, confidence: string}>
     */
    public function implementations(Node $rule): array
    {
        return array_map(
            static fn (array $row): array => ['edge_id' => (int) $row['edge_id'], 'id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT e.id AS edge_id, f.id, f.path, e.origin, e.confidence
                    FROM edge e JOIN node f ON f.id = e.target_id
                    WHERE e.source_id = :rule AND e.relation = 'implemented_by' AND e.status = :active AND f.type = :file
                    ORDER BY f.path
                    SQL,
                ['rule' => $rule->getId(), 'active' => EdgeStatus::Active->value, 'file' => NodeType::CodeFile->value],
            ),
        );
    }

    /**
     * @return list<array{edge_id: int, id: int, title: string, format: string, origin: string, confidence: string}>
     */
    public function sources(Node $rule): array
    {
        return array_map(
            static fn (array $row): array => ['edge_id' => (int) $row['edge_id'], 'id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT e.id AS edge_id, d.id, d.label AS title, COALESCE(d.metadata->>'format', 'md') AS format, e.origin, e.confidence
                    FROM edge e JOIN node d ON d.id = e.source_id
                    WHERE e.target_id = :rule AND e.relation = 'defines' AND e.status = :active AND d.type = :document
                    ORDER BY d.label
                    SQL,
                ['rule' => $rule->getId(), 'active' => EdgeStatus::Active->value, 'document' => NodeType::Document->value],
            ),
        );
    }

    /**
     * @return list<array{id: int, path: string}>
     */
    public function codeCandidates(Node $rule, string $query): array
    {
        $query = trim($query);
        if ('' === $query) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'path' => (string) $row['path']],
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT f.id, f.path FROM node f
                    WHERE f.project_id = :project AND f.type = :file AND f.path ILIKE :like
                      AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = :rule AND e.target_id = f.id AND e.relation = 'implemented_by' AND e.status = :active)
                    ORDER BY length(f.path), f.path LIMIT 8
                    SQL,
                [
                    'project' => $rule->getProject()?->getId(),
                    'file' => NodeType::CodeFile->value,
                    'rule' => $rule->getId(),
                    'active' => EdgeStatus::Active->value,
                    'like' => '%'.addcslashes($query, '\\%_').'%',
                ],
            ),
        );
    }

    /**
     * @return list<array{id: int, label: string, format: string}>
     */
    public function documentCandidates(Node $rule, string $query): array
    {
        $query = trim($query);
        if ('' === $query) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label'], 'format' => (string) $row['format']],
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT d.id, d.label, COALESCE(d.metadata->>'format', 'md') AS format FROM node d
                    WHERE d.type = :document AND d.label ILIKE :like
                      AND (
                          EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id AND np.project_id = :project)
                          OR NOT EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id)
                      )
                      AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = d.id AND e.target_id = :rule AND e.relation = 'defines' AND e.status = :active)
                    ORDER BY d.label LIMIT 8
                    SQL,
                [
                    'document' => NodeType::Document->value,
                    'project' => $rule->getProject()?->getId(),
                    'rule' => $rule->getId(),
                    'active' => EdgeStatus::Active->value,
                    'like' => '%'.addcslashes($query, '\\%_').'%',
                ],
            ),
        );
    }

    /**
     * @param list<string>|null $allowedSlugs
     *
     * @return list<array{rule_id: int, rule_code: string, rule_title: string, rule_statement: ?string, project_slug: string, project_name: string, area_index: int, severity: string, category: string, gap: string, question: string}>
     */
    public function openQuestions(?Project $project, ?string $severity, ?array $allowedSlugs): array
    {
        if ([] === $allowedSlugs) {
            return [];
        }

        $scopeSql = null === $allowedSlugs ? '' : ' AND p.slug IN (:slugs)';
        $severitySql = null === $severity ? '' : " AND sa.elem->>'severity' = :severity";
        $params = ['project' => $project?->getId()]
            + (null === $allowedSlugs ? [] : ['slugs' => $allowedSlugs])
            + (null === $severity ? [] : ['severity' => $severity]);
        $types = null === $allowedSlugs ? [] : ['slugs' => ArrayParameterType::STRING];

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT r.id AS rule_id, r.external_id AS rule_code, r.label AS rule_title, r.metadata->>'statement' AS rule_statement,
                       p.slug AS project_slug, p.name AS project_name,
                       (sa.ord - 1) AS area_index,
                       sa.elem->>'severity' AS severity,
                       sa.elem->>'category' AS category,
                       sa.elem->>'gap' AS gap,
                       sa.elem->>'question' AS question
                FROM node r
                JOIN project p ON p.id = r.project_id
                CROSS JOIN LATERAL jsonb_array_elements(r.metadata->'shadow_areas') WITH ORDINALITY AS sa(elem, ord)
                WHERE r.type = 'business_rule'
                  AND jsonb_typeof(r.metadata->'shadow_areas') = 'array'
                  AND (CAST(:project AS int) IS NULL OR r.project_id = :project){$scopeSql}{$severitySql}
                ORDER BY CASE sa.elem->>'severity' WHEN 'blocker' THEN 0 WHEN 'major' THEN 1 ELSE 2 END, p.name, r.external_id, sa.ord
                SQL,
            $params,
            $types,
        );

        return array_map(
            static fn (array $row): array => ['rule_id' => (int) $row['rule_id'], 'area_index' => (int) $row['area_index']] + $row,
            $rows,
        );
    }

    /**
     * @param list<string>|null $allowedSlugs
     *
     * @return list<array<string, mixed>>
     */
    public function alignmentRows(?Project $project, ?array $allowedSlugs): array
    {
        if ([] === $allowedSlugs) {
            return [];
        }

        $scopeSql = null === $allowedSlugs ? '' : ' AND p.slug IN (:slugs)';
        $projectSql = null === $project ? ' AND p.archived_at IS NULL' : ' AND r.project_id = :project';
        $activeMem = NodeSql::activeMemory('m');

        return $this->connection->fetchAllAssociative(
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
                             AND m.type = :memory AND {$activeMem}
                             AND m.metadata->'verification'->>'verdict' = 'refuted'
                             AND {$this->memoryAccess->sql('m')}
                       ) AS refuted_memories,
                       (
                           SELECT COUNT(*)
                           FROM edge e
                           JOIN node m ON m.id = e.source_id
                           WHERE e.target_id = r.id AND e.relation = 'about' AND e.status = :active
                             AND m.type = :memory AND {$activeMem}
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
            ] + (null === $project ? [] : ['project' => $project->getId()]) + (null === $allowedSlugs ? [] : ['slugs' => $allowedSlugs]),
            null === $allowedSlugs ? [] : ['slugs' => ArrayParameterType::STRING],
        );
    }
}
