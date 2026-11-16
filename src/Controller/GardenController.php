<?php

namespace App\Controller;

use App\Entity\Project;
use App\Garden\DuplicateFinder;
use App\Garden\GardenChecker;
use App\Graph\KnowledgeReader;
use App\Llm\LlmUnavailable;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class GardenController extends AbstractController
{
    public function __construct(
        private readonly GardenChecker $checker,
        private readonly DuplicateFinder $duplicateFinder,
        private readonly KnowledgeReader $knowledgeReader,
    ) {
    }

    #[Route('/projects/{slug}/garden', name: 'garden_show', methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project): Response
    {
        return $this->render('garden/show.html.twig', [
            'project' => $project,
            'findings' => $this->checker->check($project),
            'pending' => $this->knowledgeReader->pendingProposals($project),
        ]);
    }

    #[Route('/projects/{slug}/garden/duplicates', name: 'garden_duplicates', methods: ['POST'])]
    public function duplicates(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('garden-'.$project->getSlug(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            $report = $this->duplicateFinder->find($project);
            $this->addFlash('success', new TranslatableMessage('flash.garden.compared', ['compared' => $report['compared'], 'proposed' => $report['proposed']]));
        } catch (LlmUnavailable|\UnexpectedValueException $exception) {
            $this->addFlash('error', $exception instanceof LlmUnavailable ? $exception->translatable() : $exception->getMessage());
        }

        return $this->redirectToRoute('garden_show', ['slug' => $project->getSlug()]);
    }
}
