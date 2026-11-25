<?php

namespace App\Controller;

use App\Entity\Project;
use App\Grooming\GroomingContextCollector;
use App\Grooming\GroomingRunRepository;
use App\Grooming\GroomingSwarmRunner;
use App\Llm\LlmUnavailable;
use App\Security\ProjectScope;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class GroomingController extends AbstractController
{
    public function __construct(
        private readonly GroomingSwarmRunner $runner,
        private readonly GroomingContextCollector $contextCollector,
        private readonly GroomingRunRepository $runRepository,
        private readonly ProjectScope $projectScope,
        private readonly LoggerInterface $logger,
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

        if ('' === $topic) {
            $this->addFlash('error', new TranslatableMessage('grooming.flash.topic_required'));

            return $this->redirectToRoute('project_grooming', ['slug' => $project->getSlug()]);
        }

        try {
            $report = $this->runner->run(
                $project,
                $topic,
                '' === $context ? null : $context,
                $this->projectScope->viewer()?->getEmail(),
            );
        } catch (LlmUnavailable $exception) {
            $this->addFlash('error', $exception->translatable());

            return $this->redirectToRoute('project_grooming', ['slug' => $project->getSlug(), 'topic' => $topic]);
        } catch (\Throwable $exception) {
            $this->logger->error('Grooming swarm failed for project {slug}: {message}', [
                'slug' => $project->getSlug(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $this->addFlash('error', new TranslatableMessage('flash.llm.error', ['error' => $exception->getMessage()]));

            return $this->redirectToRoute('project_grooming', ['slug' => $project->getSlug(), 'topic' => $topic]);
        }

        $this->addFlash('success', new TranslatableMessage('grooming.flash.completed', [
            'topic' => $report->run->getTopic(),
            'score' => $report->readinessScore,
            'proposals' => \count($report->createdProposals),
            'questions' => \count($report->questions),
        ]));

        return $this->redirectToRoute('project_grooming_show', [
            'slug' => $project->getSlug(),
            'id' => $report->run->getId(),
        ]);
    }

    #[Route('/projects/{slug}/grooming/{id}', name: 'project_grooming_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        int $id,
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
        ]);
    }

    private function denyUnlessProjectAllowed(Project $project): void
    {
        if (!$this->projectScope->allows($project)) {
            throw $this->createNotFoundException('Project not found.');
        }
    }
}
