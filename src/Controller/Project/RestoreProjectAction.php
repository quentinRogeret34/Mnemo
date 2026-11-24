<?php

namespace App\Controller\Project;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/projects/{slug}/restore', name: 'project_restore', methods: ['POST'])]
final class RestoreProjectAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('project-'.$project->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $project->restore();
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.project.restored', ['name' => $project->getName()]));

        return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug()]);
    }
}
