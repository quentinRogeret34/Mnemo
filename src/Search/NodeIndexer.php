<?php

namespace App\Search;

use App\Document\DocumentTextExtractor;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the embeddings of memories, business rules and documents in the node_embedding table (pgvector): one per
 * node with the model of the instance, plus one with the model of each of its projects that names its own.
 * A node is embedded again only when its text or a model changed.
 */
final class NodeIndexer
{
    public const TYPES = [NodeType::Memory, NodeType::BusinessRule, NodeType::Document];
    private const MAX_CHARS = 6000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Embedder $embedder,
        private readonly DocumentTextExtractor $documentTextExtractor,
    ) {
    }

    /**
     * @return bool whether an embedding was written
     */
    public function index(Node $node): bool
    {
        if (!\in_array($node->getType(), self::TYPES, true) || !$this->embedder->isAvailable()) {
            return false;
        }
        $text = $this->text($node);
        if ('' === $text) {
            return false;
        }
        $models = [$this->embedder->model() => null];
        foreach (array_filter([$node->getProject(), ...$node->getProjects()]) as $project) {
            $models[$this->embedder->model($project)] ??= $project;
        }
        $connection = $this->entityManager->getConnection();
        $hashes = $connection->fetchAllKeyValue('SELECT model, content_hash FROM node_embedding WHERE node_id = :node', ['node' => $node->getId()]);
        $connection->executeStatement(
            'DELETE FROM node_embedding WHERE node_id = :node AND model NOT IN (:models)',
            ['node' => $node->getId(), 'models' => array_keys($models)],
            ['models' => ArrayParameterType::STRING],
        );

        $written = false;
        foreach ($models as $model => $project) {
            $hash = hash('sha256', $model."\n".$text);
            if ($hash === ($hashes[$model] ?? null)) {
                continue;
            }
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO node_embedding (node_id, model, content_hash, embedding, updated_at)
                    VALUES (:node, :model, :hash, CAST(:vector AS vector), now())
                    ON CONFLICT (node_id, model) DO UPDATE SET content_hash = EXCLUDED.content_hash, embedding = EXCLUDED.embedding, updated_at = now()
                    SQL,
                ['node' => $node->getId(), 'model' => $model, 'hash' => $hash, 'vector' => self::literal($this->embedder->embed($text, $project))],
            );
            $written = true;
        }

        return $written;
    }

    /**
     * @return array{indexed: int, unchanged: int}
     */
    public function indexAll(?Project $project): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT n.id FROM node n
                WHERE n.type IN (:types)
                  AND (
                      CAST(:project AS int) IS NULL OR n.project_id = :project
                      OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project)
                  )
                ORDER BY n.id
                SQL,
            ['types' => array_column(self::TYPES, 'value'), 'project' => $project?->getId()],
            ['types' => ArrayParameterType::STRING],
        );

        $report = ['indexed' => 0, 'unchanged' => 0];
        foreach ($ids as $id) {
            $node = $this->entityManager->find(Node::class, $id);
            ++$report[null !== $node && $this->index($node) ? 'indexed' : 'unchanged'];
            $this->entityManager->clear();
        }

        return $report;
    }

    /**
     * @param list<float> $vector
     */
    public static function literal(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }

    private function text(Node $node): string
    {
        $metadata = $node->getMetadata();
        $parts = [$node->getLabel(), $metadata['statement'] ?? null, $metadata['adr']['decision'] ?? null];
        if (NodeType::Document === $node->getType()) {
            $parts[] = $this->documentTextExtractor->extract($node);
        }

        return mb_substr(trim(implode("\n", array_filter($parts, static fn (mixed $part): bool => \is_string($part) && '' !== $part))), 0, self::MAX_CHARS);
    }
}
