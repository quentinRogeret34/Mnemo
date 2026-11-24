<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Encapsulates SQL queries around BusinessRule nodes: linked code, source documents, candidate search, and open shadow-area questions.
 */
final readonly class RuleRepository
{
    public function __construct(
        private Connection $connection,
    ) {
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
}
