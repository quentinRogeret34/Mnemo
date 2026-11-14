<?php

namespace App\Search;

use App\Entity\Project;
use App\Enum\NodeType;
use App\Graph\KnowledgeReader;
use App\Security\MemoryAccess;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Keyword search merged with vector search when embeddings exist; keyword alone otherwise.
 */
final class HybridSearch
{
    public const KEYWORD = 'keyword';
    public const HYBRID = 'hybrid';
    /** Reciprocal rank fusion constant: the usual value, which keeps the first ranks from crushing the rest. */
    private const RRF = 60;

    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly Embedder $embedder,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly MemoryAccess $memoryAccess,
    ) {
    }

    /**
     * @param list<string> $types node types to search, among memory, document, business_rule, code_file
     *
     * @return array{mode: string, results: list<array{id: int, type: string, label: string, path: ?string, code: string, project_slug: ?string, snippet: ?string}>}
     */
    public function search(string $query, ?Project $project, array $types, int $limit): array
    {
        $keyword = $this->knowledgeReader->search($query, $project, $types, $limit);
        $semantic = $this->nearest($query, $project, $types, $limit);
        if (null === $semantic) {
            return ['mode' => self::KEYWORD, 'results' => $keyword];
        }

        $scores = $rows = [];
        foreach ([$keyword, $semantic] as $list) {
            foreach ($list as $rank => $row) {
                $rows[$row['id']] ??= $row;
                $scores[$row['id']] = ($scores[$row['id']] ?? 0) + 1 / (self::RRF + $rank + 1);
            }
        }
        arsort($scores);

        return ['mode' => self::HYBRID, 'results' => array_map(static fn (int $id): array => $rows[$id], \array_slice(array_keys($scores), 0, $limit))];
    }

    /**
     * Nodes close to the text, nearest in meaning first, completed by the ones sharing words with it.
     *
     * @param list<string> $types   node types among memory, document, business_rule
     * @param list<int>    $exclude node ids to leave out
     *
     * @return list<array{id: int, type: string, label: string, code: string, path: ?string, statement: ?string}>
     */
    public function related(string $text, Project $project, array $types, int $limit, array $exclude = []): array
    {
        $rows = [];
        foreach ([...($this->nearest($text, $project, $types, $limit, $exclude) ?? []), ...$this->knowledgeReader->related($text, $project, $types, $limit, $exclude)] as $row) {
            $rows[$row['id']] ??= ['id' => $row['id'], 'type' => $row['type'], 'label' => $row['label'], 'code' => $row['code'], 'path' => $row['path'], 'statement' => $row['statement'] ?? null];
        }

        return \array_slice(array_values($rows), 0, $limit);
    }

    /**
     * @param list<string> $types
     * @param list<int>    $exclude
     *
     * @return list<array<string, mixed>>|null null when vector search cannot run: no key, nothing indexed, or the embedding call failed
     */
    private function nearest(string $text, ?Project $project, array $types, int $limit, array $exclude = []): ?array
    {
        $types = array_values(array_intersect($types, array_column(NodeIndexer::TYPES, 'value')));
        if ('' === trim($text) || [] === $types || !$this->embedder->isAvailable()
            || !$this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM node_embedding WHERE model = :model)', ['model' => $this->embedder->model($project)])) {
            return null;
        }

        try {
            $vector = NodeIndexer::literal($this->embedder->embed($text, $project));
        } catch (\Throwable $exception) {
            $this->logger->warning('Vector search skipped, the embedding call failed: {message}', ['message' => $exception->getMessage()]);

            return null;
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id']] + $row,
            $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT n.id, n.type, n.label, n.path, n.external_id AS code, p.slug AS project_slug, n.metadata->>'statement' AS statement,
                           CASE WHEN n.type = :rule THEN n.metadata->>'statement' WHEN n.type = :memory THEN n.metadata->'adr'->>'decision' END AS snippet
                    FROM node_embedding v JOIN node n ON n.id = v.node_id LEFT JOIN project p ON p.id = n.project_id
                    WHERE v.model = :model AND n.type IN (:types) AND n.id NOT IN (:exclude)
                      AND (n.type <> :memory OR (COALESCE(n.metadata->>'status', 'active') = 'active' AND {$this->memoryAccess->sql('n')}))
                      AND (
                          CAST(:project AS int) IS NULL OR n.project_id = :project
                          OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project)
                      )
                    ORDER BY v.embedding <=> CAST(:vector AS vector)
                    LIMIT $limit
                    SQL,
                ['model' => $this->embedder->model($project), 'types' => $types, 'exclude' => [0, ...$exclude], 'project' => $project?->getId(), 'vector' => $vector, 'rule' => NodeType::BusinessRule->value, 'memory' => NodeType::Memory->value],
                ['types' => ArrayParameterType::STRING, 'exclude' => ArrayParameterType::INTEGER],
            ),
        );
    }
}
