<?php

namespace App\Graph;

use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class GraphReader
{
    private const NEIGHBOURHOOD_MAX_FILES = 60;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{slug: string, name: string, files: int, symbols: int, edges: int, ref: ?string, updated_at: ?string}>
     */
    public function projectSummaries(): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.slug, p.name,
                       COUNT(n.id) FILTER (WHERE n.type = :file) AS files,
                       COUNT(n.id) FILTER (WHERE n.type = :symbol) AS symbols,
                       (SELECT COUNT(*) FROM edge e JOIN node s ON s.id = e.source_id WHERE s.project_id = p.id) AS edges,
                       MAX(n.metadata->>'ref') AS ref,
                       MAX(n.updated_at) AS updated_at
                FROM project p
                LEFT JOIN node n ON n.project_id = p.id
                GROUP BY p.id
                ORDER BY p.name
                SQL,
            ['file' => NodeType::CodeFile->value, 'symbol' => NodeType::Symbol->value],
        );
    }

    /**
     * Matches the query against file paths and symbol names.
     *
     * @return array{total: int, files: list<array{path: string, symbols: int, matched_symbols: ?string}>}
     */
    public function searchFiles(Project $project, string $query, int $limit = 200): array
    {
        $params = [
            'project' => $project->getId(),
            'file' => NodeType::CodeFile->value,
            'symbol' => NodeType::Symbol->value,
            'query' => trim($query),
            'like' => '%'.addcslashes(trim($query), '\\%_').'%',
        ];

        $where = <<<'SQL'
            f.project_id = :project AND f.type = :file
            AND (:query = '' OR f.path ILIKE :like OR EXISTS (
                SELECT 1 FROM node s WHERE s.project_id = f.project_id AND s.path = f.path AND s.type = :symbol AND s.label ILIKE :like
            ))
            SQL;

        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM node f WHERE $where", $params);

        $files = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT f.path,
                       (SELECT COUNT(*) FROM node s WHERE s.project_id = f.project_id AND s.path = f.path AND s.type = :symbol) AS symbols,
                       CASE WHEN :query = '' THEN NULL ELSE (
                           SELECT array_to_string((array_agg(s.label ORDER BY s.line))[1:3], ', ')
                           FROM node s WHERE s.project_id = f.project_id AND s.path = f.path AND s.type = :symbol AND s.label ILIKE :like
                       ) END AS matched_symbols
                FROM node f
                WHERE $where
                ORDER BY f.path
                LIMIT $limit
                SQL,
            $params,
        );

        return ['total' => $total, 'files' => $files];
    }

    /**
     * @return array{id: int, label: string, path: string, metadata: array<string, mixed>, updated_at: string}|null
     */
    public function findFile(Project $project, string $path): ?array
    {
        $file = $this->connection->fetchAssociative(
            'SELECT id, label, path, metadata, updated_at FROM node WHERE project_id = :project AND type = :file AND path = :path',
            ['project' => $project->getId(), 'file' => NodeType::CodeFile->value, 'path' => $path],
        );

        if (false === $file) {
            return null;
        }

        $file['id'] = (int) $file['id'];
        $file['metadata'] = json_decode($file['metadata'], true, flags: \JSON_THROW_ON_ERROR);

        return $file;
    }

    /**
     * @return list<array{label: string, line: ?int}>
     */
    public function symbols(Project $project, string $path): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT label, line FROM node WHERE project_id = :project AND type = :symbol AND path = :path ORDER BY line NULLS LAST, label',
            ['project' => $project->getId(), 'symbol' => NodeType::Symbol->value, 'path' => $path],
        );
    }

    /**
     * Edges between this file (or its symbols) and other files, split by direction.
     *
     * @return array{outgoing: list<array<string, mixed>>, incoming: list<array<string, mixed>>}
     */
    public function crossFileEdges(Project $project, string $path): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT e.relation, e.confidence, e.evidence,
                       s.label AS source_label, s.path AS source_path, s.type AS source_type,
                       t.label AS target_label, t.path AS target_path, t.type AS target_type
                FROM edge e
                JOIN node s ON s.id = e.source_id
                JOIN node t ON t.id = e.target_id
                WHERE s.project_id = :project AND e.status = :active
                  AND s.path <> t.path AND (s.path = :path OR t.path = :path)
                ORDER BY e.relation, t.path, s.path, s.label
                SQL,
            ['project' => $project->getId(), 'active' => EdgeStatus::Active->value, 'path' => $path],
        );

        $edges = ['outgoing' => [], 'incoming' => []];
        foreach ($rows as $row) {
            $edges[$row['source_path'] === $path ? 'outgoing' : 'incoming'][] = $row;
        }

        return $edges;
    }

    /**
     * File-level neighbourhood: symbol edges are folded onto the files that hold them.
     *
     * @return array{nodes: list<array{id: string, label: string, dir: string, center: bool}>, edges: list<array{source: string, target: string, relations: list<string>, count: int, inferred: bool}>}
     */
    public function neighbourhood(Project $project, string $path, int $depth): array
    {
        $paths = [$path => true];
        $links = [];

        for ($hop = 0; $hop < $depth; ++$hop) {
            $links = $this->fileLinks($project, array_keys($paths));
            $weights = [];
            foreach ($links as $link) {
                foreach ([$link['source_path'], $link['target_path']] as $linked) {
                    if (!isset($paths[$linked])) {
                        $weights[$linked] = ($weights[$linked] ?? 0) + $link['count'];
                    }
                }
            }
            arsort($weights);
            foreach (\array_slice(array_keys($weights), 0, max(0, self::NEIGHBOURHOOD_MAX_FILES - \count($paths))) as $linked) {
                $paths[$linked] = true;
            }
        }

        $edges = [];
        foreach ($links as $link) {
            if (!isset($paths[$link['source_path']], $paths[$link['target_path']])) {
                continue;
            }
            $key = $link['source_path'].'|'.$link['target_path'];
            $edges[$key] ??= ['source' => $link['source_path'], 'target' => $link['target_path'], 'relations' => [], 'count' => 0, 'inferred' => true];
            $edges[$key]['relations'][] = $link['relation'];
            $edges[$key]['count'] += $link['count'];
            $edges[$key]['inferred'] = $edges[$key]['inferred'] && $link['inferred'] === $link['count'];
        }

        $nodes = [];
        foreach (array_keys($paths) as $nodePath) {
            $nodePath = (string) $nodePath;
            $slash = strrpos($nodePath, '/');
            $nodes[] = [
                'id' => $nodePath,
                'label' => false === $slash ? $nodePath : substr($nodePath, $slash + 1),
                'dir' => false === $slash ? '' : substr($nodePath, 0, $slash),
                'center' => $nodePath === $path,
            ];
        }

        return ['nodes' => $nodes, 'edges' => array_values($edges)];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<array{source_path: string, target_path: string, relation: string, count: int, inferred: int}>
     */
    private function fileLinks(Project $project, array $paths): array
    {
        return array_map(
            static fn (array $row): array => ['count' => (int) $row['count'], 'inferred' => (int) $row['inferred']] + $row,
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT s.path AS source_path, t.path AS target_path, e.relation,
                           COUNT(*) AS count, COUNT(*) FILTER (WHERE e.confidence = :inferred) AS inferred
                    FROM edge e
                    JOIN node s ON s.id = e.source_id
                    JOIN node t ON t.id = e.target_id
                    WHERE s.project_id = :project AND e.status = :active AND s.path <> t.path
                      AND (s.path IN (:paths) OR t.path IN (:paths))
                    GROUP BY s.path, t.path, e.relation
                    SQL,
                ['project' => $project->getId(), 'active' => EdgeStatus::Active->value, 'inferred' => Confidence::Inferred->value, 'paths' => $paths],
                ['paths' => ArrayParameterType::STRING],
            ),
        );
    }
}
