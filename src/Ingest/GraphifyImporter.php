<?php

namespace App\Ingest;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;

final class GraphifyImporter
{
    private const SUPPORTED_SCHEMA_VERSION = 1;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function import(Project $project, string $graphFile): GraphifyImportResult
    {
        $graph = json_decode(file_get_contents($graphFile), true, flags: \JSON_THROW_ON_ERROR);

        $schemaVersion = $graph['graph']['schema_version'] ?? null;
        if (self::SUPPORTED_SCHEMA_VERSION !== $schemaVersion) {
            throw new \InvalidArgumentException(\sprintf('Unsupported graphify schema version "%s", expected %d.', $schemaVersion ?? 'none', self::SUPPORTED_SCHEMA_VERSION));
        }

        $commit = $graph['built_at_commit'] ?? null;

        $staleNodes = [];
        foreach ($this->entityManager->getRepository(Node::class)->findBy(['project' => $project, 'type' => [NodeType::CodeFile, NodeType::Symbol]]) as $node) {
            $staleNodes[$node->getType()->value.'|'.$node->getExternalId()] = $node;
        }

        $nodesCreated = $nodesUpdated = 0;
        /** @var array<string, Node> $nodesByGraphifyId */
        $nodesByGraphifyId = [];

        foreach ($graph['nodes'] as $rawNode) {
            // Package and external-reference nodes are dependencies, not code of this project.
            if ('code' !== $rawNode['file_type'] || isset($rawNode['type'])) {
                continue;
            }

            $path = $rawNode['source_file'];
            $label = $rawNode['label'];
            $type = $this->isFileNode($rawNode) ? NodeType::CodeFile : NodeType::Symbol;
            $line = isset($rawNode['source_location']) ? (int) ltrim($rawNode['source_location'], 'L') : null;
            $metadata = ['community' => $rawNode['community'] ?? null, 'ref' => $commit];

            $key = $type->value.'|'.$rawNode['id'];
            $node = $staleNodes[$key] ?? null;
            unset($staleNodes[$key]);

            if (null === $node) {
                $node = new Node($project, $type, $rawNode['id'], $label, $path, $line, $metadata);
                $this->entityManager->persist($node);
                ++$nodesCreated;
            } elseif ($node->update($label, $path, $line, $metadata)) {
                ++$nodesUpdated;
            }

            $nodesByGraphifyId[$rawNode['id']] = $node;
        }

        $staleEdges = [];
        foreach ($this->findAstEdges($project) as $edge) {
            $staleEdges[$this->edgeKey($edge->getSource()->getExternalId(), $edge->getTarget()->getExternalId(), $edge->getRelation())] = $edge;
        }

        $edgesCreated = 0;
        $seenEdges = [];

        foreach ($graph['links'] as $rawEdge) {
            $source = $nodesByGraphifyId[$rawEdge['source']] ?? null;
            $target = $nodesByGraphifyId[$rawEdge['target']] ?? null;
            if (null === $source || null === $target) {
                continue;
            }

            $key = $this->edgeKey($rawEdge['source'], $rawEdge['target'], $rawEdge['relation']);
            if (isset($seenEdges[$key])) {
                continue;
            }
            $seenEdges[$key] = true;

            if (isset($staleEdges[$key])) {
                unset($staleEdges[$key]);
                continue;
            }

            $evidence = $rawEdge['source_file'].(isset($rawEdge['source_location']) ? ':'.$rawEdge['source_location'] : '');

            $this->entityManager->persist(new Edge(
                $source,
                $target,
                $rawEdge['relation'],
                Confidence::tryFrom($rawEdge['confidence']) ?? Confidence::Inferred,
                EdgeOrigin::Ast,
                $evidence,
            ));
            ++$edgesCreated;
        }

        foreach ($staleEdges as $edge) {
            $this->entityManager->remove($edge);
        }
        foreach ($staleNodes as $node) {
            $this->entityManager->remove($node);
        }

        $this->entityManager->flush();

        return new GraphifyImportResult($nodesCreated, $nodesUpdated, \count($staleNodes), $edgesCreated, \count($staleEdges), $commit);
    }

    /**
     * graphify has no explicit file kind: a file node is the non-callable node whose label is a suffix of its own path.
     *
     * @param array<string, mixed> $rawNode
     */
    private function isFileNode(array $rawNode): bool
    {
        if ($rawNode['_callable'] ?? false) {
            return false;
        }

        $path = $rawNode['source_file'];

        return $rawNode['label'] === $path || str_ends_with($path, '/'.$rawNode['label']);
    }

    /**
     * @return list<Edge>
     */
    private function findAstEdges(Project $project): array
    {
        return $this->entityManager->createQuery(
            'SELECT e, s, t FROM App\Entity\Edge e JOIN e.source s JOIN e.target t WHERE s.project = :project AND e.origin = :origin'
        )
            ->setParameter('project', $project)
            ->setParameter('origin', EdgeOrigin::Ast)
            ->getResult();
    }

    private function edgeKey(string $source, string $target, string $relation): string
    {
        return $source.'|'.$target.'|'.$relation;
    }
}
