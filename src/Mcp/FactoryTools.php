<?php

namespace App\Mcp;

use App\Entity\AgentSession;
use App\Entity\Project;
use App\Entity\SwarmMessage;
use App\Entity\SwarmNode;
use App\Entity\SwarmOrder;
use App\Entity\SwarmRun;
use App\Enum\AgentRunnerKind;
use App\Enum\AgentSessionEventKind;
use App\Enum\AgentSessionStatus;
use App\Enum\SwarmMessageKind;
use App\Factory\AgentSessionRecorder;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\ProjectContextBuilder;
use App\Factory\Swarm\SwarmHubManager;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Repository\AgentSessionRepository;
use App\Repository\SwarmNodeRepository;
use App\Repository\SwarmOrderRepository;
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
        private readonly SwarmHubManager $hubManager,
        private readonly SwarmNodeRepository $nodeRepository,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly ProjectContextBuilder $contextBuilder,
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

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'join_swarm',
        description: 'Join an active interactive Swarm Hub as an external worker node to receive live work orders and Mnemo project context.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
    )]
    public function joinSwarm(
        string $swarmId,
        #[Schema(minLength: 1, maxLength: 100)]
        string $name,
        #[Schema(maxLength: 100)]
        ?string $client = null,
        #[Schema(minLength: 1, maxLength: 60)]
        string $role = 'developer',
        #[Schema(maxLength: 200)]
        ?string $branch = null,
    ): array {
        $this->assertEnabled();
        $swarm = $this->swarm($swarmId);
        if (!$swarm->getStatus()->isActive()) {
            throw new ToolCallException(\sprintf('Swarm "%s" is no longer active (%s).', $swarm->getPublicId(), $swarm->getStatus()->value));
        }

        $cleanName = trim($name);
        if ('' === $cleanName) {
            throw new ToolCallException('Node name must not be blank.');
        }

        $node = $this->hubManager->joinNode(
            swarm: $swarm,
            name: $cleanName,
            client: $client,
            role: $role,
            branch: $branch,
            startedBy: $this->projectScope->key()?->getName() ?? 'mcp',
        );

        $pendingOrders = $this->orderRepository->pendingForSwarm($swarm);

        return [
            'node_id' => $node->getPublicId(),
            'session_id' => $node->getSession()?->getPublicId(),
            'swarm_id' => $swarm->getPublicId(),
            'swarm_status' => $swarm->getStatus()->value,
            'project' => $swarm->getProject()->getSlug(),
            'project_context' => $this->contextBuilder->build($swarm->getProject(), $swarm->getSourceRuleCode()),
            'pending_orders' => array_map(static fn (SwarmOrder $order): array => [
                'order_id' => $order->getPublicId(),
                'kind' => $order->getKind()->value,
                'title' => $order->getTitle(),
                'target_node_id' => $order->getTargetNode()?->getPublicId(),
                'files_hint' => $order->getFilesHint(),
                'rule_code' => $order->getRuleCode(),
            ], $pendingOrders),
            'url' => $this->urlGenerator->generate('factory_swarm_show', ['publicId' => $swarm->getPublicId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'wait_swarm_order',
        description: 'Long-poll an interactive Swarm Hub for the next eligible work order. Loop while status is "idle", execute when "order", stop when "closed".',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
    )]
    public function waitSwarmOrder(
        string $swarmId,
        string $nodeId,
        #[Schema(minimum: 0, maximum: 25)]
        int $timeout = 20,
    ): array {
        $this->assertEnabled();
        $swarm = $this->swarm($swarmId);
        $node = $this->swarmNode($swarm, $nodeId);

        $order = $swarm->getStatus()->isActive() ? $this->hubManager->waitForOrder($node, $timeout) : null;

        if (null !== $order) {
            return [
                'status' => 'order',
                'swarm_status' => $swarm->getStatus()->value,
                'order' => [
                    'order_id' => $order->getPublicId(),
                    'kind' => $order->getKind()->value,
                    'title' => $order->getTitle(),
                    'instructions' => $order->getInstructions(),
                    'files_hint' => $order->getFilesHint(),
                    'rule_code' => $order->getRuleCode(),
                    'project_context' => $this->contextBuilder->build(
                        $swarm->getProject(),
                        $order->getRuleCode() ?? $swarm->getSourceRuleCode(),
                    ),
                ],
            ];
        }

        if (!$swarm->getStatus()->isActive()) {
            return [
                'status' => 'closed',
                'swarm_status' => $swarm->getStatus()->value,
            ];
        }

        return [
            'status' => 'idle',
            'swarm_status' => $swarm->getStatus()->value,
        ];
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'report_swarm_order',
        description: 'Report an intermediate progress update and touched files while executing a Swarm Hub work order.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
    )]
    public function reportSwarmOrder(
        string $swarmId,
        string $orderId,
        #[Schema(minLength: 1)]
        string $message,
        ?string $nodeId = null,
        #[Schema(items: ['type' => 'string'])]
        array $files = [],
    ): array {
        $this->assertEnabled();
        $swarm = $this->swarm($swarmId);
        $order = $this->swarmOrder($swarm, $orderId);

        $message = trim($message);
        if ('' === $message) {
            throw new ToolCallException('Progress message must not be blank.');
        }

        $node = null !== $nodeId && '' !== trim($nodeId) ? $this->swarmNode($swarm, $nodeId) : $order->getClaimedByNode();
        $this->hubManager->reportProgress($node, $order, $message, $files);

        return [
            'order_id' => $order->getPublicId(),
            'status' => $order->getStatus()->value,
            'files_touched' => $order->getFilesTouched(),
        ];
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'complete_swarm_order',
        description: 'Complete or fail a Swarm Hub work order with a summary, optional Markdown output, touched files and branch.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function completeSwarmOrder(
        string $swarmId,
        string $orderId,
        #[Schema(minLength: 1)]
        string $summary,
        #[Schema(enum: ['completed', 'failed'])]
        string $status = 'completed',
        ?string $output = null,
        #[Schema(items: ['type' => 'string'])]
        array $files = [],
        ?string $branch = null,
        ?string $nodeId = null,
    ): array {
        $this->assertEnabled();
        $swarm = $this->swarm($swarmId);
        $order = $this->swarmOrder($swarm, $orderId);

        $summary = trim($summary);
        if ('' === $summary) {
            throw new ToolCallException('Order summary must not be blank.');
        }

        if (!\in_array($status, ['completed', 'failed'], true)) {
            throw new ToolCallException(\sprintf('Invalid order status "%s". Use completed or failed.', $status));
        }

        $node = null !== $nodeId && '' !== trim($nodeId) ? $this->swarmNode($swarm, $nodeId) : $order->getClaimedByNode();
        $this->hubManager->completeOrder(
            node: $node,
            order: $order,
            succeeded: 'completed' === $status,
            summary: $summary,
            output: $output,
            filesTouched: $files,
            branch: $branch,
        );

        return [
            'order_id' => $order->getPublicId(),
            'status' => $order->getStatus()->value,
            'files_touched' => $order->getFilesTouched(),
            'branch' => $order->getBranch(),
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

    private function swarmNode(SwarmRun $swarm, string $publicId): SwarmNode
    {
        $node = $this->nodeRepository->findByPublicId($publicId);
        if (null === $node || $node->getSwarm()->getId() !== $swarm->getId()) {
            throw new ToolCallException(\sprintf('Unknown node "%s" for swarm "%s".', $publicId, $swarm->getPublicId()));
        }

        return $node;
    }

    private function swarmOrder(SwarmRun $swarm, string $publicId): SwarmOrder
    {
        $order = $this->orderRepository->findByPublicId($publicId);
        if (null === $order || $order->getSwarm()->getId() !== $swarm->getId()) {
            throw new ToolCallException(\sprintf('Unknown order "%s" for swarm "%s".', $publicId, $swarm->getPublicId()));
        }

        return $order;
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
