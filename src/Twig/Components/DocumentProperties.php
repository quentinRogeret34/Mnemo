<?php

namespace App\Twig\Components;

use App\Document\DocumentLinker;
use App\Entity\Folder;
use App\Entity\Node;
use App\Entity\Project;
use App\Graph\KnowledgeReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use App\Security\ProjectScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class DocumentProperties
{
    use DefaultActionTrait;

    #[LiveProp]
    public Node $document;

    #[LiveProp(writable: true, onUpdated: 'onFolderUpdated')]
    public ?int $folderId = null;

    #[LiveProp(writable: true)]
    public string $fileQuery = '';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KnowledgeReader $knowledgeReader,
        private readonly DocumentLinker $documentLinker,
        private readonly ProjectScope $projectScope,
    ) {
    }

    public function mount(Node $document): void
    {
        $this->document = $document;
        $this->guard();
        $this->folderId = $document->getFolder()?->getId();
    }

    /**
     * @return list<Project>
     */
    public function getProjects(): array
    {
        return $this->projectScope->projects();
    }

    /**
     * A live action rebuilds the component from the document id sent by the browser: checked again each time.
     */
    #[PostHydrate]
    public function guard(): void
    {
        if (!$this->projectScope->allowsNode($this->document)) {
            throw new NotFoundHttpException('Document not found.');
        }
    }

    /**
     * @return list<array{id: int, name: string, parent_id: ?int, depth: int, documents: int}>
     */
    public function getFolders(): array
    {
        return $this->knowledgeReader->folderTree();
    }

    /**
     * @return list<array{edge_id: int, path: string, project_slug: string, project_name: string, confidence: string, origin: string}>
     */
    public function getCodeLinks(): array
    {
        return $this->knowledgeReader->documentCodeLinks($this->document);
    }

    /**
     * @return list<array{code: string, title: string, statement: ?string, status: ?string, confidence: string, origin: string, project_slug: string}>
     */
    public function getRules(): array
    {
        return $this->knowledgeReader->rulesDefinedBy($this->document);
    }

    /**
     * @return list<array{id: int, path: string, project_name: string}>
     */
    public function getFileResults(): array
    {
        return $this->knowledgeReader->linkableFiles($this->document, $this->fileQuery);
    }

    public function onFolderUpdated(): void
    {
        $this->document->moveTo(null === $this->folderId ? null : $this->entityManager->find(Folder::class, $this->folderId));
        $this->entityManager->flush();
    }

    #[LiveAction]
    public function toggleProject(#[LiveArg] int $projectId): void
    {
        $project = $this->entityManager->find(Project::class, $projectId) ?? throw new \InvalidArgumentException('Unknown project.');
        if (!$this->projectScope->allows($project)) {
            throw new \InvalidArgumentException('Unknown project.');
        }
        $this->document->getProjects()->contains($project) ? $this->documentLinker->detachProject($this->document, $project) : $this->document->addProject($project);
        $this->entityManager->flush();
    }

    #[LiveAction]
    public function linkFile(#[LiveArg] int $fileId): void
    {
        $file = $this->entityManager->find(Node::class, $fileId) ?? throw new \InvalidArgumentException('Unknown file.');
        $this->documentLinker->linkCode($this->document, $file);
        $this->entityManager->flush();
        $this->fileQuery = '';
    }

    #[LiveAction]
    public function unlinkFile(#[LiveArg] int $edgeId): void
    {
        $this->documentLinker->unlinkCode($this->document, $edgeId);
        $this->entityManager->flush();
    }
}
