<?php

namespace App\Controller;

use App\Document\DocumentStorage;
use App\Document\DocumentTextExtractor;
use App\Entity\Folder;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Form\DocumentUploadType;
use App\Form\FolderType;
use App\Graph\KnowledgeReader;
use App\Llm\LlmUnavailable;
use App\Pagination\Page;
use App\Rule\RuleExtractor;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DocumentController extends AbstractController
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly DocumentStorage $documentStorage,
        private readonly DocumentTextExtractor $documentTextExtractor,
        private readonly RuleExtractor $ruleExtractor,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/documents', name: 'document_index', methods: ['GET', 'POST'])]
    public function index(Request $request, #[MapQueryParameter] ?int $folder = null, #[MapQueryParameter] int $page = 1): Response
    {
        $page = max(1, $page);
        $current = null === $folder ? null : ($this->entityManager->find(Folder::class, $folder) ?? throw $this->createNotFoundException(\sprintf('Folder %d not found.', $folder)));

        $upload = $this->createForm(DocumentUploadType::class);
        if ($this->handleUpload($upload, $request, $current, null)) {
            return $this->redirectToRoute('document_index', ['folder' => $folder]);
        }

        $folderForm = $this->createForm(FolderType::class);
        $folderForm->handleRequest($request);
        if ($folderForm->isSubmitted() && $folderForm->isValid()) {
            $name = trim((string) $folderForm->get('name')->getData());
            $exists = false !== $this->entityManager->getConnection()->fetchOne(
                'SELECT 1 FROM folder WHERE lower(name) = lower(:name) AND '.(null === $current ? 'parent_id IS NULL' : 'parent_id = :parent').' LIMIT 1',
                ['name' => $name] + (null === $current ? [] : ['parent' => $current->getId()]),
            );
            if ($exists) {
                $folderForm->get('name')->addError(new FormError($this->translator->trans('folder.duplicate', domain: 'validators')));
            } else {
                $created = new Folder($name, $current);
                $this->entityManager->persist($created);
                $this->entityManager->flush();

                return $this->redirectToRoute('document_index', ['folder' => $created->getId()]);
            }
        }

        $ancestors = [];
        for ($ancestor = $current; null !== $ancestor; $ancestor = $ancestor->getParent()) {
            array_unshift($ancestors, $ancestor);
        }

        return $this->render('document/index.html.twig', [
            'current' => $current,
            'ancestors' => $ancestors,
            'folders' => $this->knowledgeReader->folderTree(),
            'files' => $this->knowledgeReader->documentTree(),
            'documents' => Page::ofRows($this->knowledgeReader->documents(folderId: $folder, limit: self::PAGE_SIZE, offset: Page::offset($page, self::PAGE_SIZE)), $page, self::PAGE_SIZE),
            'upload' => $upload,
            'folder_form' => $folderForm,
        ], new Response(status: $upload->isSubmitted() || $folderForm->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/projects/{slug}', name: 'project_show', methods: ['GET', 'POST'])]
    public function project(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request, #[MapQueryParameter] int $page = 1): Response
    {
        $page = max(1, $page);
        $upload = $this->createForm(DocumentUploadType::class);
        if ($this->handleUpload($upload, $request, null, $project)) {
            return $this->redirectToRoute('project_show', ['slug' => $project->getSlug()]);
        }

        return $this->render('document/project.html.twig', [
            'project' => $project,
            'documents' => Page::ofRows($this->knowledgeReader->documents($project, limit: self::PAGE_SIZE, offset: Page::offset($page, self::PAGE_SIZE)), $page, self::PAGE_SIZE),
            'upload' => $upload,
        ], new Response(status: $upload->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/documents/{id}', name: 'document_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $document = $this->findDocument($id);

        $ancestors = [];
        for ($ancestor = $document->getFolder(); null !== $ancestor; $ancestor = $ancestor->getParent()) {
            array_unshift($ancestors, $ancestor);
        }

        $format = (string) ($document->getMetadata()['format'] ?? 'md');
        $extractableProjects = array_values(array_filter(
            $document->getProjects()->toArray(),
            fn (Project $project): bool => !$project->isArchived() && $this->projectScope->allows($project),
        ));

        return $this->render('document/show.html.twig', [
            'document' => $document,
            'ancestors' => $ancestors,
            'folders' => $this->knowledgeReader->folderTree(),
            'files' => $this->knowledgeReader->documentTree(),
            'markdown' => 'md' === $format ? $this->documentStorage->markdownBody($document) : null,
            'extracted_text' => 'docx' === $format ? $this->documentTextExtractor->extract($document) : null,
            'extractable_projects' => $extractableProjects,
            'rules' => $this->knowledgeReader->rulesDefinedBy($document),
            'memories' => $this->knowledgeReader->memoriesAbout($document->getId()),
        ]);
    }

    #[Route('/documents/{id}/extract-rules', name: 'document_extract_rules', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function extractRules(int $id, Request $request): Response
    {
        $document = $this->findDocument($id);
        if (!$this->isCsrfTokenValid('document-extract-'.$document->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $slug = trim($request->getPayload()->getString('project'));
        $target = null;
        foreach ($document->getProjects() as $attached) {
            if ($this->projectScope->allows($attached) && ('' === $slug || $attached->getSlug() === $slug)) {
                $target = $attached;
                break;
            }
        }
        if (null === $target) {
            $this->addFlash('error', new TranslatableMessage('flash.document.extract_no_project'));

            return $this->redirectToRoute('document_show', ['id' => $document->getId()]);
        }

        try {
            $report = $this->ruleExtractor->extract($document, $target);
        } catch (LlmUnavailable|\UnexpectedValueException $exception) {
            $this->addFlash('error', $exception instanceof LlmUnavailable ? $exception->translatable() : $exception->getMessage());

            return $this->redirectToRoute('document_show', ['id' => $document->getId()]);
        } catch (\Throwable $exception) {
            $this->logger->error('Rule extraction failed for document {id}: {message}', ['id' => $id, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $this->addFlash('error', new TranslatableMessage('flash.llm.error', ['error' => $exception->getMessage()]));

            return $this->redirectToRoute('document_show', ['id' => $document->getId()]);
        }

        if ($report['empty']) {
            $this->addFlash('error', new TranslatableMessage('flash.document.extract_empty'));

            return $this->redirectToRoute('document_show', ['id' => $document->getId()]);
        }

        if ($report['proposed'] > 0) {
            $this->addFlash('success', new TranslatableMessage('flash.document.rules_extracted', ['count' => $report['proposed']]));

            return $this->redirectToRoute('proposal_index', ['project' => $target->getSlug(), 'kind' => ProposalKind::RuleDraft->value]);
        }

        $this->addFlash('success', new TranslatableMessage('flash.document.rules_extracted_none'));

        return $this->redirectToRoute('document_show', ['id' => $document->getId()]);
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
        if ('pdf' === $format) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'");
        }

        return $response;
    }

    /**
     * @param FormInterface<array<string, mixed>> $form
     */
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
        $this->addFlash('success', new TranslatableMessage('flash.document.added', ['count' => \count($files)]));

        return true;
    }

    private function findDocument(int $id): Node
    {
        $document = $this->entityManager->getRepository(Node::class)->findOneBy(['id' => $id, 'type' => NodeType::Document]);

        return null !== $document && $this->projectScope->allowsNode($document) ? $document : throw $this->createNotFoundException(\sprintf('Document %d not found.', $id));
    }
}
