<?php

namespace App\Document;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manual links between a document and code files: CodeFile -[documented_by]-> Document, VERIFIED by a human.
 */
final class DocumentLinker
{
    private const RELATION = 'documented_by';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function linkCode(Node $document, Node $file): void
    {
        if (NodeType::CodeFile !== $file->getType() || !$document->getProjects()->contains($file->getProject())) {
            throw new \InvalidArgumentException('Only code files of the document\'s projects can be linked.');
        }

        $edge = $this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $file, 'target' => $document, 'relation' => self::RELATION]);
        if (null !== $edge) {
            $edge->restore();

            return;
        }

        $this->entityManager->persist(new Edge($file, $document, self::RELATION, Confidence::Verified, EdgeOrigin::Human, 'lien manuel'));
    }

    /**
     * Links from the project's code files are rejected too: only code of the document's projects can be linked.
     */
    public function detachProject(Node $document, Project $project): void
    {
        $document->removeProject($project);
        $edges = $this->entityManager->createQuery('SELECT e FROM App\Entity\Edge e JOIN e.source s WHERE e.target = :document AND e.relation = :relation AND s.project = :project')
            ->setParameters(['document' => $document, 'relation' => self::RELATION, 'project' => $project])
            ->getResult();
        foreach ($edges as $edge) {
            $edge->reject();
        }
    }

    /**
     * The edge is kept as rejected so that a later automatic ingestion does not recreate it.
     */
    public function unlinkCode(Node $document, int $edgeId): void
    {
        $this->entityManager->getRepository(Edge::class)->findOneBy(['id' => $edgeId, 'target' => $document, 'relation' => self::RELATION])?->reject();
    }
}
