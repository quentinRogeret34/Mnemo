<?php

namespace App\Controller;

use App\Document\DocumentStorage;
use App\Entity\Folder;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use App\Form\DocumentUploadType;
use App\Form\FolderType;
use App\Graph\KnowledgeReader;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly DocumentStorage $documentStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/documents', name: 'document_index', methods: ['GET', 'POST'])]
    public function index(Request $request, #[MapQueryParameter] ?int $folder = null): Response
    {
        $current = null === $folder ? null : ($this->entityManager->find(Folder::class, $folder) ?? throw $this->createNotFoundException(\sprintf('Folder %d not found.', $folder)));

        $upload = $this->createForm(DocumentUploadType::class);
        if ($this->handleUpload($upload, $request, $current, null)) {
            return $this->redirectToRoute('document_index', ['folder' => $folder]);
        }

        $folderForm = $this->createForm(FolderType::class);
        $folderForm->handleRequest($request);
        if ($folderForm->isSubmitted() && $folderForm->isValid()) {
            $created = new Folder(trim($folderForm->get('name')->getData()), $current);
            $this->entityManager->persist($created);
            $this->entityManager->flush();

            return $this->redirectToRoute('document_index', ['folder' => $created->getId()]);
        }

        $ancestors = [];
        for ($ancestor = $current; null !== $ancestor; $ancestor = $ancestor->getParent()) {
            array_unshift($ancestors, $ancestor);
        }

        return $this->render('document/index.html.twig', [
            'current' => $current,
            'ancestors' => $ancestors,
            'folders' => $this->knowledgeReader->folderTree(),
            'documents' => $this->knowledgeReader->documents(folderId: $folder),
            'upload' => $upload,
            'folder_form' => $folderForm,
        ], new Response(status: $upload->isSubmitted() || $folderForm->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/projects/{slug}', name: 'project_show', methods: ['GET', 'POST'])]
    public function project(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $upload = $this->createForm(DocumentUploadType::class);
        if ($this->handleUpload($upload, $request, null, $project)) {
            return $this->redirectToRoute('project_show', ['slug' => $project->getSlug()]);
        }

        return $this->render('document/project.html.twig', [
            'project' => $project,
            'documents' => $this->knowledgeReader->documents($project),
            'upload' => $upload,
        ], new Response(status: $upload->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/documents/{id}', name: 'document_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $document = $this->findDocument($id);

        return $this->render('document/show.html.twig', [
            'document' => $document,
            'markdown' => 'md' === $document->getMetadata()['format'] ? $this->documentStorage->markdownBody($document) : null,
            'rules' => $this->knowledgeReader->rulesDefinedBy($document),
            'memories' => $this->knowledgeReader->memoriesAbout($document->getId()),
        ]);
    }

    #[Route('/documents/{id}/file', name: 'document_file', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id): BinaryFileResponse
    {
        $document = $this->findDocument($id);
        $format = $document->getMetadata()['format'];

        $response = $this->file(
            $this->documentStorage->absolutePath($document),
            $document->getPath(),
            'pdf' === $format ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );
        // Without a declared type the response would be sniffed from the bytes, and HTML passed off as a PDF would run inline.
        $response->headers->set('Content-Type', match ($format) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'text/markdown; charset=UTF-8',
        });
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function handleUpload(FormInterface $form, Request $request, ?Folder $folder, ?Project $project): bool
    {
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return false;
        }

        /** @var list<UploadedFile> $files */
        $files = $form->get('files')->getData();
        foreach ($files as $file) {
            $document = $this->documentStorage->store($folder, $file, $file->getClientOriginalName());
            if (null !== $project) {
                $document->addProject($project);
            }
        }
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('%d %s ajouté%s.', \count($files), \count($files) > 1 ? 'documents' : 'document', \count($files) > 1 ? 's' : ''));

        return true;
    }

    private function findDocument(int $id): Node
    {
        $document = $this->entityManager->getRepository(Node::class)->findOneBy(['id' => $id, 'type' => NodeType::Document]);

        return null !== $document && $this->projectScope->allowsNode($document) ? $document : throw $this->createNotFoundException(\sprintf('Document %d not found.', $id));
    }
}
