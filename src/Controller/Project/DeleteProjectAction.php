<?php

namespace App\Controller\Project;

use App\Entity\Project;
use App\Project\ProjectRemover;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/projects/{slug}/delete', name: 'project_delete', methods: ['POST'])]
final class DeleteProjectAction extends AbstractController
{
    public function __construct(
        private readonly ProjectRemover $projectRemover,
    ) {
    }

    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('project-'.$project->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        if ($project->getSlug() !== trim($request->getPayload()->getString('confirm'))) {
            $this->addFlash('error', new TranslatableMessage('flash.project.slug_mismatch'));

            return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug(), '_fragment' => 'danger']);
        }
        $name = $project->getName();
        $this->projectRemover->remove($project);
        $this->addFlash('success', new TranslatableMessage('flash.project.deleted', ['name' => $name]));

        return $this->redirectToRoute('project_index');
    }
}
