<?php

namespace App\Controller\Studio;

use App\Document\DocumentStorage;
use App\Entity\AgentAsset;
use App\Entity\Project;
use App\Enum\AgentAssetKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class StudioCreateAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentStorage $documentStorage,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/projects/{slug}/studio/create', name: 'project_studio_create', methods: ['POST'])]
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Project $project,
        Request $request,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('studio-create-'.$project->getSlug(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $kind = trim($request->request->getString('kind'));
        $rawName = trim($request->request->getString('name'));
        $description = trim($request->request->getString('description'));
        $folder = trim($request->request->getString('folder')) ?: null;

        if ('' === $rawName) {
            $this->addFlash('error', 'studio.flash.name_required');

            return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug()]);
        }

        if ('document' === $kind) {
            $markdown = \sprintf("# %s\n\n%s\n", $rawName, '' !== $description ? $description : 'Décrire ici la spécification, les règles métier ou les décisions.');
            $document = $this->documentStorage->storeContent(
                $this->documentStorage->folder($folder),
                $markdown,
                $rawName,
                'md',
                ['source' => 'studio'],
            );
            $document->addProject($project);
            $this->entityManager->flush();

            $this->addFlash('success', new TranslatableMessage('studio.flash.created', ['name' => $document->getLabel()]));

            return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug(), 'file' => 'doc:'.$document->getId()]);
        }

        $assetKind = AgentAssetKind::tryFrom($kind);
        if (null === $assetKind) {
            $this->addFlash('error', 'studio.flash.invalid_kind');

            return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug()]);
        }

        $slug = (new AsciiSlugger())->slug($rawName)->lower()->toString();
        $asset = new AgentAsset($project);
        $asset->setKind($assetKind);
        $asset->setName($slug);
        $asset->setDescription('' !== $description ? $description : \sprintf('%s pour %s', $rawName, $project->getName()));
        $asset->setContent(\sprintf("# %s\n\n## Objectif\n\nDécrire quand et comment les agents doivent appliquer ces instructions.\n", $rawName));

        $violations = $this->validator->validate($asset);
        if (\count($violations) > 0) {
            $this->addFlash('error', (string) $violations->get(0)->getMessage());

            return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug()]);
        }

        $this->entityManager->persist($asset);
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('studio.flash.created', ['name' => $asset->path()]));

        return $this->redirectToRoute('project_studio', ['slug' => $project->getSlug(), 'file' => 'asset:'.$asset->getId()]);
    }
}
