<?php

namespace App\Controller\Factory;

use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Enum\AgentSessionEventKind;
use App\Enum\AgentSessionStatus;
use App\Factory\AgentSessionRecorder;
use App\Factory\Dto\SessionHookPayload;
use App\Factory\FactoryGuard;
use App\Repository\AgentSessionRepository;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Stateless JSON hook receiver (Claude Code hooks, shell wrappers, CI jobs) authenticated by the api_keys firewall.
 */
final class HookSessionAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly AgentSessionRecorder $recorder,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/api/factory/sessions/hook', name: 'api_factory_session_hook', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] SessionHookPayload $payload): JsonResponse
    {
        $this->factoryGuard->requireEnabled();

        return match ($payload->action) {
            'start' => $this->handleStart($payload),
            'event' => $this->handleEvent($payload),
            'end' => $this->handleEnd($payload),
            default => $this->json(['error' => 'Unsupported action.'], Response::HTTP_BAD_REQUEST),
        };
    }

    private function handleStart(SessionHookPayload $payload): JsonResponse
    {
        $slug = trim((string) $payload->project);
        $project = '' === $slug ? null : $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (null === $project || !$this->projectScope->allows($project)) {
            return $this->json(['error' => \sprintf('Unknown project "%s".', $slug)], Response::HTTP_NOT_FOUND);
        }

        $title = trim((string) $payload->title);
        if ('' === $title) {
            $title = \sprintf('%s session', $payload->client ?: 'Agent');
        }

        $runner = null !== $payload->runner ? (AgentRunnerKind::tryFrom($payload->runner) ?? AgentRunnerKind::ExternalMcp) : AgentRunnerKind::ExternalMcp;
        $parent = null !== $payload->parentSessionId && '' !== trim($payload->parentSessionId)
            ? $this->sessionRepository->findByPublicId($payload->parentSessionId)
            : null;
        if (null !== $parent && !$this->projectScope->allows($parent->getProject())) {
            $parent = null;
        }

        $session = $this->recorder->start(
            project: $project,
            title: $title,
            runner: $runner,
            role: trim((string) $payload->role) ?: 'developer',
            goal: $payload->goal,
            client: $payload->client,
            branch: $payload->branch,
            sourceRuleCode: $payload->ruleCode,
            startedBy: $this->projectScope->key()?->getName() ?? 'hook',
            parentSession: $parent,
        );

        return $this->json([
            'session_id' => $session->getPublicId(),
            'status' => $session->getStatus()->value,
            'url' => $this->generateUrl('factory_session_show', ['publicId' => $session->getPublicId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ], Response::HTTP_CREATED);
    }

    private function handleEvent(SessionHookPayload $payload): JsonResponse
    {
        $session = null !== $payload->sessionId ? $this->sessionRepository->findByPublicId($payload->sessionId) : null;
        if (null === $session || !$this->projectScope->allows($session->getProject())) {
            return $this->json(['error' => 'Unknown session.'], Response::HTTP_NOT_FOUND);
        }

        $message = trim((string) $payload->message);
        if ('' === $message) {
            return $this->json(['error' => 'Event message must not be blank.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $kind = null !== $payload->kind ? (AgentSessionEventKind::tryFrom($payload->kind) ?? AgentSessionEventKind::Step) : AgentSessionEventKind::Step;
        $event = $this->recorder->recordEvent($session, $kind, $message, $payload->files, $payload->payload);

        return $this->json([
            'event_id' => $event->getId(),
            'session_id' => $session->getPublicId(),
            'kind' => $event->getKind()->value,
            'files_touched' => $session->getFilesTouched(),
        ]);
    }

    private function handleEnd(SessionHookPayload $payload): JsonResponse
    {
        $session = null !== $payload->sessionId ? $this->sessionRepository->findByPublicId($payload->sessionId) : null;
        if (null === $session || !$this->projectScope->allows($session->getProject())) {
            return $this->json(['error' => 'Unknown session.'], Response::HTTP_NOT_FOUND);
        }

        $status = null !== $payload->status ? AgentSessionStatus::tryFrom($payload->status) : AgentSessionStatus::Succeeded;
        if (null === $status || !$status->isTerminal()) {
            $status = AgentSessionStatus::Succeeded;
        }

        $this->recorder->finish($session, $status, $payload->summary, $payload->files, $payload->payload);

        return $this->json([
            'session_id' => $session->getPublicId(),
            'status' => $session->getStatus()->value,
            'duration_seconds' => $session->durationSeconds(),
            'files_touched' => $session->getFilesTouched(),
        ]);
    }
}
