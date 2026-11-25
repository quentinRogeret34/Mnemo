<?php

namespace App\Controller;

use App\Entity\GroomingRun;
use App\Entity\Project;
use App\Grooming\GroomingContextCollector;
use App\Grooming\GroomingRunRepository;
use App\Grooming\GroomingSwarmRunner;
use App\Message\RunGroomingSwarm;
use App\Security\ProjectScope;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class GroomingController extends AbstractController
{
    public function __construct(
        private readonly GroomingSwarmRunner $runner,
        private readonly GroomingContextCollector $contextCollector,
        private readonly GroomingRunRepository $runRepository,
        private readonly ProjectScope $projectScope,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/projects/{slug}/grooming', name: 'project_grooming', methods: ['GET'])]
    public function index(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        #[MapQueryParameter] ?string $topic = null,
    ): Response {
        $this->denyUnlessProjectAllowed($project);

        return $this->render('grooming/index.html.twig', [
            'project' => $project,
            'prefill_topic' => null === $topic ? '' : mb_substr(trim($topic), 0, 255),
            'suggested_topics' => $this->contextCollector->suggestTopics($project),
            'runs' => $this->runRepository->recentForProject($project),
        ]);
    }

    #[Route('/projects/{slug}/grooming', name: 'project_grooming_run', methods: ['POST'])]
    public function run(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        Request $request,
    ): Response {
        $this->denyUnlessProjectAllowed($project);

        if (!$this->isCsrfTokenValid('grooming-run-'.$project->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $topic = trim($request->getPayload()->getString('topic'));
        $context = trim($request->getPayload()->getString('context'));
        $rounds = max(1, min(GroomingRun::MAX_ROUNDS, $request->getPayload()->getInt('rounds', GroomingRun::DEFAULT_ROUNDS)));

        if ('' === $topic) {
            $this->addFlash('error', new TranslatableMessage('grooming.flash.topic_required'));

            return $this->redirectToRoute('project_grooming', ['slug' => $project->getSlug()]);
        }

        $run = $this->runner->start(
            $project,
            $topic,
            '' === $context ? null : $context,
            $rounds,
            $this->projectScope->viewer()?->getEmail(),
        );

        $runId = $run->getId();
        if (null !== $runId) {
            $this->messageBus->dispatch(new RunGroomingSwarm($runId));
        }

        $this->addFlash('success', new TranslatableMessage('grooming.flash.started', [
            'topic' => $run->getTopic(),
            'rounds' => $run->getRounds(),
        ]));

        return $this->redirectToRoute('project_grooming_show', [
            'slug' => $project->getSlug(),
            'id' => $runId,
            'started' => 1,
        ]);
    }

    #[Route('/projects/{slug}/grooming/{id}', name: 'project_grooming_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        int $id,
        #[MapQueryParameter] bool $started = false,
    ): Response {
        $this->denyUnlessProjectAllowed($project);

        $run = $this->runRepository->findForProject($project, $id);
        if (null === $run) {
            throw $this->createNotFoundException('Grooming run not found.');
        }

        return $this->render('grooming/show.html.twig', [
            'project' => $project,
            'run' => $run,
            'proposals' => $this->runRepository->findProposalsForRun($run),
            'show_running_modal' => $started && $run->isRunning(),
        ]);
    }

    #[Route('/projects/{slug}/grooming/{id}/status', name: 'project_grooming_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        int $id,
    ): JsonResponse {
        $this->denyUnlessProjectAllowed($project);

        $run = $this->runRepository->findForProject($project, $id);
        if (null === $run) {
            throw $this->createNotFoundException('Grooming run not found.');
        }

        $steps = $run->getSteps();
        $latest = [] === $steps ? null : $steps[\count($steps) - 1];

        return $this->json([
            'status' => $run->getStatus(),
            'rounds' => $run->getRounds(),
            'steps_count' => \count($steps),
            'expected_steps' => $run->expectedStepsCount(),
            'readiness_score' => $run->getReadinessScore(),
            'proposals_count' => \count($run->getCreatedProposalIds()),
            'questions_count' => \count($run->getQuestions()),
            'latest_role' => $latest['role'] ?? null,
            'latest_round' => $latest['round'] ?? null,
        ]);
    }

    private function denyUnlessProjectAllowed(Project $project): void
    {
        if (!$this->projectScope->allows($project)) {
            throw $this->createNotFoundException('Project not found.');
        }
    }
}
