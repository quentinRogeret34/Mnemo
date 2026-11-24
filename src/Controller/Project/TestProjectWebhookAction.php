<?php

namespace App\Controller\Project;

use App\Entity\Project;
use App\Notification\WorkspaceNotifier;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/projects/{slug}/settings/webhook-test', name: 'project_webhook_test', methods: ['POST'])]
final class TestProjectWebhookAction extends AbstractController
{
    public function __construct(
        private readonly WorkspaceNotifier $notifier,
    ) {
    }

    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('project-'.$project->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            $this->notifier->test($project);
            $this->addFlash('success', new TranslatableMessage('flash.project.webhook_sent'));
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', new TranslatableMessage('flash.project.webhook_failed', ['error' => $exception->getMessage()]));
        }

        return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug()]);
    }
}
