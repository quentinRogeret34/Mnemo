<?php

namespace App\Controller\Studio;

use App\Document\DocumentStorage;
use App\Entity\AgentAsset;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class StudioSaveAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentStorage $documentStorage,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/projects/{slug}/studio/save', name: 'project_studio_save', methods: ['POST'])]
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Project $project,
        Request $request,
    ): RedirectResponse {
        $ref = trim($request->request->getString('ref'));
        if (!$this->isCsrfTokenValid('studio-save-'.$project->getSlug().'-'.$ref, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $content = $request->request->getString('content');

        if (str_starts_with($ref, 'asset:')) {
            $asset = $this->entityManager->getRepository(AgentAsset::class)->findOneBy([
                'id' => (int) substr($ref, 6),
                'project' => $project,
            ]);
            if (null === $asset) {
                throw $this->createNotFoundException('Agent asset not found.');
            }
            $description = trim($request->request->getString('description'));
            if ('' !== $description) {
                $asset->setDescription($description);
            }
            $asset->setContent($content);
            $violations = $this->validator->validate($asset);
            if (\count($violations) > 0) {
                $this->addFlash('error', (string) $violations->get(0)->getMessage());

                return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug(), 'file' => $ref]);
            }
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('studio.flash.saved', ['name' => $asset->path()]));

            return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug(), 'file' => $ref]);
        }

        if (str_starts_with($ref, 'doc:')) {
            $document = $this->entityManager->getRepository(Node::class)->findOneBy([
                'id' => (int) substr($ref, 4),
                'type' => NodeType::Document,
            ]);
            if (null === $document || !$document->getProjects()->contains($project) || 'md' !== ($document->getMetadata()['format'] ?? null)) {
                throw $this->createNotFoundException('Markdown document not found.');
            }
            if ('' === trim($content)) {
                $this->addFlash('error', 'studio.flash.empty_content');

                return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug(), 'file' => $ref]);
            }
            $this->documentStorage->replaceContent($document, $content);
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('studio.flash.saved', ['name' => $document->getLabel()]));

            return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug(), 'file' => $ref]);
        }

        throw $this->createNotFoundException('Editable studio file not found.');
    }
}
