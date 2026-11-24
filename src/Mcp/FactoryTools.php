<?php

namespace App\Mcp;

use App\Entity\AgentSession;
use App\Entity\Project;
use App\Entity\SwarmMessage;
use App\Entity\SwarmRun;
use App\Enum\AgentRunnerKind;
use App\Enum\AgentSessionEventKind;
use App\Enum\AgentSessionStatus;
use App\Enum\SwarmMessageKind;
use App\Factory\AgentSessionRecorder;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Repository\AgentSessionRepository;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * MCP tools allowing external IDE/CLI agents (Claude Code, Cursor, Codex, Antigravity) and swarm workers
 * to register sessions, log milestones and coordinate work inside Mnemo's Software Factory.
 */
final class FactoryTools
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly AgentSessionRecorder $recorder,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrchestrator $swarmOrchestrator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'start_session',
        description: 'Register a new agent working session in Mnemo Software Factory (dev mode). Call at the beginning of a coding/review task to track progress, branch, business rule and sub-agent hierarchy.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
    )]
    public function startSession(
        string $project,
        #[Schema(minLength: 1, maxLength: 255)]
        string $title,
        #[Schema(minLength: 1, maxLength: 60)]
        string $role = 'developer',
        ?string $goal = null,
        ?string $client = null,
        ?string $branch = null,
        ?string $ruleCode = null,
        ?string $parentSessionId = null,
        #[Schema(enum: ['external_mcp', 'claude_cli', 'codex_cli', 'gemini_cli', 'internal_llm'])]
        string $runner = 'external_mcp',
    ): array {
        $this->assertEnabled();
        $projectEntity = $this->project($project);

        $title = trim($title);
        if ('' === $title) {
            throw new ToolCallException('Session title must not be blank.');
        }

        $runnerEnum = AgentRunnerKind::tryFrom($runner) ?? throw new ToolCallException(\sprintf('Unknown runner "%s".', $runner));
        $parent = null;
        if (null !== $parentSessionId && '' !== trim($parentSessionId)) {
            $parent = $this->session($parentSessionId);
        }

        $session = $this->recorder->start(
            project: $projectEntity,
            title: $title,
            runner: $runnerEnum,
            role: $role,
            goal: $goal,
            client: $client,
            branch: $branch,
            sourceRuleCode: $ruleCode,
            startedBy: $this->projectScope->key()?->getName() ?? 'mcp',
            parentSession: $parent,
        );

        return [
            'session_id' => $session->getPublicId(),
            'status' => $session->getStatus()->value,
            'project' => $projectEntity->getSlug(),
            'url' => $this->urlGenerator->generate('factory_session_show', ['publicId' => $session->getPublicId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /**
     * @param list<string>         $files
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'log_session_event',
        description: 'Append a milestone, tool call, file modification, decision, handoff or error to an active Software Factory agent session.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
    )]
    public function logSessionEvent(
        string $sessionId,
        #[Schema(enum: ['step', 'tool_call', 'file_changed', 'log', 'decision', 'handoff', 'error'])]
        string $kind,
        #[Schema(minLength: 1)]
        string $message,
        #[Schema(items: ['type' => 'string'])]
        array $files = [],
        array $payload = [],
    ): array {
        $this->assertEnabled();
        $session = $this->session($sessionId);
        $kindEnum = AgentSessionEventKind::tryFrom($kind) ?? throw new ToolCallException(\sprintf('Unknown event kind "%s".', $kind));
        $message = trim($message);
        if ('' === $message) {
            throw new ToolCallException('Event message must not be blank.');
        }

        $event = $this->recorder->recordEvent($session, $kindEnum, $message, $files, $payload);

        return [
            'event_id' => $event->getId(),
            'session_id' => $session->getPublicId(),
            'kind' => $event->getKind()->value,
            'files_touched' => $session->getFilesTouched(),
        ];
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'end_session',
        description: 'Close a Software Factory agent session with its final status (succeeded, failed or cancelled), summary and touched files.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function endSession(
        string $sessionId,
        #[Schema(enum: ['succeeded', 'failed', 'cancelled'])]
        string $status = 'succeeded',
        ?string $summary = null,
        #[Schema(items: ['type' => 'string'])]
        array $files = [],
    ): array {
        $this->assertEnabled();
        $session = $this->session($sessionId);
        $statusEnum = AgentSessionStatus::tryFrom($status);
        if (null === $statusEnum || !$statusEnum->isTerminal()) {
            throw new ToolCallException(\sprintf('Invalid terminal status "%s". Use succeeded, failed or cancelled.', $status));
        }

        $this->recorder->finish($session, $statusEnum, $summary, $files);

        return [
            'session_id' => $session->getPublicId(),
            'status' => $session->getStatus()->value,
            'duration_seconds' => $session->durationSeconds(),
            'files_touched' => $session->getFilesTouched(),
            'url' => $this->urlGenerator->generate('factory_session_show', ['publicId' => $session->getPublicId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_sessions',
        description: 'List recent or currently running agent sessions in Mnemo Software Factory to avoid duplicate work across parallel agents.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
    )]
    public function listSessions(
        ?string $project = null,
        #[Schema(enum: ['queued', 'running', 'waiting_human', 'succeeded', 'failed', 'cancelled'])]
        ?string $status = null,
        #[Schema(minimum: 1, maximum: 50)]
        int $limit = 20,
    ): array {
        $this->assertEnabled();
        $projectEntity = null !== $project && '' !== trim($project) ? $this->project($project) : null;
        $statusEnum = null !== $status && '' !== trim($status)
            ? (AgentSessionStatus::tryFrom($status) ?? throw new ToolCallException(\sprintf('Unknown status "%s".', $status)))
            : null;
        $allowed = null === $this->projectScope->slugs() ? null : $this->projectScope->projects();

        $sessions = $this->sessionRepository->recent($projectEntity, $statusEnum, $limit, $allowed);

        return [
            'count' => \count($sessions),
            'sessions' => array_map(fn (AgentSession $s): array => [
                'session_id' => $s->getPublicId(),
                'project' => $s->getProject()->getSlug(),
                'title' => $s->getTitle(),
                'role' => $s->getRole(),
                'runner' => $s->getRunner()->value,
                'client' => $s->getClient(),
                'status' => $s->getStatus()->value,
                'branch' => $s->getBranch(),
                'rule_code' => $s->getSourceRuleCode(),
                'files_touched' => $s->getFilesTouched(),
                'started_at' => $s->getStartedAt()->format(\DATE_ATOM),
                'finished_at' => $s->getFinishedAt()?->format(\DATE_ATOM),
                'url' => $this->urlGenerator->generate('factory_session_show', ['publicId' => $s->getPublicId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ], $sessions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_swarm_board',
        description: 'Read a Software Factory swarm plan, worker statuses and shared board messages to coordinate with sibling agents.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
    )]
    public function getSwarmBoard(string $swarmId): array
    {
        $this->assertEnabled();
        $swarm = $this->swarm($swarmId);
        $coordinator = $swarm->getCoordinatorSession();
        $workers = null !== $coordinator ? $this->sessionRepository->childrenOf($coordinator) : [];
        $messages = $this->swarmRepository->messagesFor($swarm);

        return [
            'swarm_id' => $swarm->getPublicId(),
            'project' => $swarm->getProject()->getSlug(),
            'title' => $swarm->getTitle(),
            'status' => $swarm->getStatus()->value,
            'strategy' => $swarm->getStrategy()->value,
            'plan' => $swarm->getPlan(),
            'workers' => array_map(static fn (AgentSession $s): array => [
                'session_id' => $s->getPublicId(),
                'title' => $s->getTitle(),
                'role' => $s->getRole(),
                'status' => $s->getStatus()->value,
                'branch' => $s->getBranch(),
                'files_touched' => $s->getFilesTouched(),
            ], $workers),
            'messages' => array_map(static fn (SwarmMessage $m): array => [
                'author' => $m->getAuthor(),
                'kind' => $m->getKind()->value,
                'body' => $m->getBody(),
                'created_at' => $m->getCreatedAt()->format(\DATE_ATOM),
            ], $messages),
            'url' => $this->urlGenerator->generate('factory_swarm_show', ['publicId' => $swarm->getPublicId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'post_swarm_message',
        description: 'Post a progress update, handoff note or blocker onto a Software Factory swarm shared message board.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
    )]
    public function postSwarmMessage(
        string $swarmId,
        #[Schema(minLength: 1)]
        string $message,
        string $author = 'agent',
        #[Schema(enum: ['progress', 'handoff', 'review', 'system'])]
        string $kind = 'progress',
        ?string $sessionId = null,
    ): array {
        $this->assertEnabled();
        $swarm = $this->swarm($swarmId);
        $message = trim($message);
        if ('' === $message) {
            throw new ToolCallException('Message body must not be blank.');
        }

        $kindEnum = SwarmMessageKind::tryFrom($kind) ?? SwarmMessageKind::Progress;
        $session = null !== $sessionId && '' !== trim($sessionId) ? $this->session($sessionId) : null;

        $posted = $this->swarmOrchestrator->postMessage($swarm, $author, $kindEnum, $message, $session);

        return [
            'message_id' => $posted->getId(),
            'swarm_id' => $swarm->getPublicId(),
            'author' => $posted->getAuthor(),
            'kind' => $posted->getKind()->value,
        ];
    }

    private function assertEnabled(): void
    {
        if (!$this->factoryGuard->isEnabled()) {
            throw new ToolCallException('Software Factory is disabled in this environment.');
        }
    }

    private function swarm(string $publicId): SwarmRun
    {
        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw new ToolCallException(\sprintf('Unknown swarm "%s".', $publicId));
        }

        return $swarm;
    }

    private function project(string $slug): Project
    {
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => trim($slug)]);
        if (null === $project || !$this->projectScope->allows($project)) {
            throw new ToolCallException(\sprintf('Unknown project "%s". Call list_projects to see available slugs.', $slug));
        }

        return $project;
    }

    private function session(string $publicId): AgentSession
    {
        $session = $this->sessionRepository->findByPublicId($publicId);
        if (null === $session || !$this->projectScope->allows($session->getProject())) {
            throw new ToolCallException(\sprintf('Unknown session "%s".', $publicId));
        }

        return $session;
    }
}
