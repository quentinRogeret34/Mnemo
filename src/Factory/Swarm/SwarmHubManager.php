<?php

namespace App\Factory\Swarm;

use App\Entity\AgentSession;
use App\Entity\SwarmMessage;
use App\Entity\SwarmNode;
use App\Entity\SwarmOrder;
use App\Entity\SwarmRun;
use App\Enum\AgentRunnerKind;
use App\Enum\AgentSessionEventKind;
use App\Enum\AgentSessionStatus;
use App\Enum\SwarmMessageKind;
use App\Enum\SwarmNodeStatus;
use App\Enum\SwarmOrderKind;
use App\Enum\SwarmOrderStatus;
use App\Enum\SwarmStatus;
use App\Factory\AgentSessionRecorder;
use App\Repository\SwarmNodeRepository;
use App\Repository\SwarmOrderRepository;
use App\Security\SecretBox;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Coordinates interactive Swarm Hub tokens, external client nodes, atomic work-order claims (FOR UPDATE SKIP LOCKED),
 * long-polling waits, progress logging and completion reporting.
 */
final class SwarmHubManager
{
    private const MAX_WAIT_SECONDS = 25;
    private const POLL_INTERVAL_MICROSECONDS = 500_000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentSessionRecorder $sessionRecorder,
        private readonly SwarmNodeRepository $nodeRepository,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly SecretBox $secretBox,
    ) {
    }

    public function enableHubToken(SwarmRun $swarm): string
    {
        if ($swarm->hasHubToken()) {
            $existing = $this->resolveHubToken($swarm);
            if (null !== $existing) {
                return $existing;
            }
        }

        return $this->rotateHubToken($swarm);
    }

    public function rotateHubToken(SwarmRun $swarm): string
    {
        $rawToken = 'mnhub_'.bin2hex(random_bytes(24));
        $hash = hash('sha256', $rawToken);
        $cipher = $this->secretBox->encrypt($rawToken);

        $swarm->setHubToken($hash, $cipher);
        $this->entityManager->flush();

        return $rawToken;
    }

    public function revokeHubToken(SwarmRun $swarm): void
    {
        $swarm->clearHubToken();
        $this->entityManager->flush();
    }

    public function resolveHubToken(SwarmRun $swarm): ?string
    {
        $cipher = $swarm->getHubTokenCipher();
        if (null === $cipher || '' === $cipher) {
            return null;
        }

        try {
            return $this->secretBox->decrypt($cipher);
        } catch (\Throwable) {
            return null;
        }
    }

    public function verifyHubToken(SwarmRun $swarm, #[\SensitiveParameter] string $rawToken): bool
    {
        $expectedHash = $swarm->getHubTokenHash();
        $trimmed = trim($rawToken);
        if (null === $expectedHash || '' === $expectedHash || '' === $trimmed) {
            return false;
        }

        if ($swarm->getStatus()->isTerminal()) {
            return false;
        }

        return hash_equals($expectedHash, hash('sha256', $trimmed));
    }

    /**
     * @param array<int|string, mixed> $capabilities
     */
    public function joinNode(
        SwarmRun $swarm,
        string $name,
        ?string $client = null,
        string $role = 'developer',
        ?string $branch = null,
        array $capabilities = [],
        ?string $startedBy = null,
    ): SwarmNode {
        $cleanName = mb_substr(trim($name) ?: 'node', 0, 100);
        $cleanRole = mb_substr(trim($role) ?: 'developer', 0, 60);

        $session = $this->sessionRecorder->start(
            project: $swarm->getProject(),
            title: \sprintf('%s · %s', $cleanName, $swarm->getTitle()),
            runner: AgentRunnerKind::ExternalHub,
            role: $cleanRole,
            goal: $swarm->getGoal(),
            client: $client,
            branch: $branch,
            sourceRuleCode: $swarm->getSourceRuleCode(),
            startedBy: $startedBy ?? $swarm->getCreatedBy() ?? 'hub-node',
            parentSession: $swarm->getCoordinatorSession(),
            initialStatus: AgentSessionStatus::Running,
            metadata: [
                'swarm_public_id' => $swarm->getPublicId(),
                'hub_node_name' => $cleanName,
            ],
        );

        $node = new SwarmNode(
            swarm: $swarm,
            name: $cleanName,
            client: $client,
            role: $cleanRole,
            branch: $branch,
            capabilities: $capabilities,
            session: $session,
        );
        $session->mergeMetadata(['hub_node_id' => $node->getPublicId()]);

        $this->entityManager->persist($node);

        $details = array_filter([
            $node->getClient(),
            $node->getRole(),
            null !== $node->getBranch() ? 'branche '.$node->getBranch() : null,
        ]);
        $this->persistBoardMessage(
            $swarm,
            $node->getName(),
            SwarmMessageKind::System,
            \sprintf('Nœud externe connecté (%s).', implode(' · ', $details)),
            $session,
        );

        $this->entityManager->flush();

        return $node;
    }

    public function heartbeat(SwarmNode $node): void
    {
        $node->heartbeat();
        $node->getSession()?->touch();
        $this->entityManager->flush();
    }

    public function disconnectNode(SwarmNode $node): void
    {
        $node->disconnect();

        $session = $node->getSession();
        if (null !== $session && $session->getStatus()->isActive()) {
            $this->sessionRecorder->finish(
                $session,
                AgentSessionStatus::Succeeded,
                \sprintf('Nœud "%s" déconnecté du Hub.', $node->getName()),
            );
        }

        $this->persistBoardMessage(
            $node->getSwarm(),
            $node->getName(),
            SwarmMessageKind::System,
            'Nœud externe déconnecté.',
            $session,
        );
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $filesHint
     */
    public function createOrder(
        SwarmRun $swarm,
        SwarmOrderKind $kind,
        string $title,
        string $instructions,
        ?SwarmNode $targetNode = null,
        array $filesHint = [],
        ?string $ruleCode = null,
        ?string $createdBy = null,
    ): SwarmOrder {
        $order = new SwarmOrder(
            swarm: $swarm,
            kind: $kind,
            title: $title,
            instructions: $instructions,
            targetNode: $targetNode,
            filesHint: $filesHint,
            ruleCode: $ruleCode,
            createdBy: $createdBy,
        );

        $this->entityManager->persist($order);

        $targetLabel = null !== $targetNode ? ' (@'.$targetNode->getName().')' : '';
        $author = null !== $createdBy && '' !== trim($createdBy) ? trim($createdBy) : 'coordinator';
        $this->persistBoardMessage(
            $swarm,
            $author,
            SwarmMessageKind::Handoff,
            \sprintf(
                '[%s] %s%s — %s',
                $kind->value,
                $order->getTitle(),
                $targetLabel,
                mb_strimwidth($order->getInstructions(), 0, 240, '…'),
            ),
            $swarm->getCoordinatorSession(),
        );

        $this->entityManager->flush();

        return $order;
    }

    /**
     * Dispatches one targeted order per online node; falls back to one untargeted pending order when no node is online.
     *
     * @param list<string> $filesHint
     *
     * @return list<SwarmOrder>
     */
    public function broadcastOrder(
        SwarmRun $swarm,
        SwarmOrderKind $kind,
        string $title,
        string $instructions,
        array $filesHint = [],
        ?string $ruleCode = null,
        ?string $createdBy = null,
    ): array {
        $nodes = $this->nodeRepository->activeForSwarm($swarm);
        if ([] === $nodes) {
            return [$this->createOrder($swarm, $kind, $title, $instructions, null, $filesHint, $ruleCode, $createdBy)];
        }

        $orders = [];
        foreach ($nodes as $node) {
            $orders[] = $this->createOrder($swarm, $kind, $title, $instructions, $node, $filesHint, $ruleCode, $createdBy);
        }

        return $orders;
    }

    public function cancelOrder(SwarmOrder $order): void
    {
        if ($order->getStatus()->isTerminal()) {
            return;
        }

        $order->cancel();

        $claimedBy = $order->getClaimedByNode();
        if (null !== $claimedBy && SwarmNodeStatus::Busy === $claimedBy->getStatus()) {
            $claimedBy->markIdle();
        }

        $this->persistBoardMessage(
            $order->getSwarm(),
            'coordinator',
            SwarmMessageKind::System,
            \sprintf('Ordre annulé : %s (`%s`).', $order->getTitle(), $order->getPublicId()),
            $order->getSwarm()->getCoordinatorSession(),
        );
        $this->entityManager->flush();
    }

    /**
     * Atomically claims the next eligible pending order for the given node using PostgreSQL FOR UPDATE SKIP LOCKED.
     */
    public function claimNextOrder(SwarmNode $node): ?SwarmOrder
    {
        $swarm = $node->getSwarm();
        $swarmId = $swarm->getId();
        $nodeId = $node->getId();
        if (null === $swarmId || null === $nodeId || !$swarm->getStatus()->isActive() || SwarmStatus::Paused === $swarm->getStatus()) {
            return null;
        }

        $connection = $this->entityManager->getConnection();

        /** @var int|null $claimedOrderId */
        $claimedOrderId = $connection->transactional(static function (Connection $conn) use ($swarmId, $nodeId): ?int {
            $row = $conn->fetchAssociative(
                <<<'SQL'
                    SELECT id
                    FROM swarm_order
                    WHERE swarm_id = :swarm
                      AND status = :pending
                      AND (target_node_id IS NULL OR target_node_id = :node)
                    ORDER BY (target_node_id IS NOT NULL) DESC, created_at ASC, id ASC
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
                SQL,
                [
                    'swarm' => $swarmId,
                    'pending' => SwarmOrderStatus::Pending->value,
                    'node' => $nodeId,
                ],
            );

            if (false === $row || !isset($row['id']) || !is_numeric($row['id'])) {
                return null;
            }

            $orderId = (int) $row['id'];
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $conn->executeStatement(
                <<<'SQL'
                    UPDATE swarm_order
                    SET status = :claimed,
                        claimed_by_node_id = :node,
                        claimed_at = :now
                    WHERE id = :id
                SQL,
                [
                    'claimed' => SwarmOrderStatus::Claimed->value,
                    'node' => $nodeId,
                    'now' => $now,
                    'id' => $orderId,
                ],
            );

            $conn->executeStatement(
                <<<'SQL'
                    UPDATE swarm_node
                    SET status = :busy,
                        last_heartbeat_at = :now
                    WHERE id = :node
                SQL,
                [
                    'busy' => SwarmNodeStatus::Busy->value,
                    'now' => $now,
                    'node' => $nodeId,
                ],
            );

            return $orderId;
        });

        if (null === $claimedOrderId) {
            return null;
        }

        $this->entityManager->refresh($node);
        $order = $this->entityManager->find(SwarmOrder::class, $claimedOrderId);
        if (null === $order) {
            return null;
        }
        $this->entityManager->refresh($order);

        $session = $node->getSession();
        if (null !== $session) {
            $this->sessionRecorder->recordEvent(
                $session,
                AgentSessionEventKind::Step,
                \sprintf('Ordre pris en charge : %s (`%s`).', $order->getTitle(), $order->getPublicId()),
                payload: ['order_id' => $order->getPublicId(), 'kind' => $order->getKind()->value],
            );
        }

        return $order;
    }

    /**
     * Long-polls for an available order up to 25 seconds (500ms interval), refreshing heartbeat and swarm active state.
     */
    public function waitForOrder(SwarmNode $node, int $timeoutSeconds = 20): ?SwarmOrder
    {
        $timeout = max(0, min(self::MAX_WAIT_SECONDS, $timeoutSeconds));
        $deadline = microtime(true) + $timeout;

        $this->heartbeat($node);

        $order = $this->claimNextOrder($node);
        if (null !== $order || 0 === $timeout) {
            return $order;
        }

        $swarm = $node->getSwarm();
        $swarmId = $swarm->getId();
        $nodeId = $node->getId();
        $connection = $this->entityManager->getConnection();
        $lastBeatAt = microtime(true);

        while (microtime(true) < $deadline) {
            usleep(self::POLL_INTERVAL_MICROSECONDS);

            if (null !== $swarmId) {
                $rawStatus = $connection->fetchOne('SELECT status FROM swarm_run WHERE id = :id', ['id' => $swarmId]);
                $statusEnum = \is_string($rawStatus) ? SwarmStatus::tryFrom($rawStatus) : null;
                if (null === $statusEnum || !$statusEnum->isActive()) {
                    $this->entityManager->refresh($swarm);

                    return null;
                }
                if (SwarmStatus::Paused === $statusEnum) {
                    continue;
                }
            }

            if (null !== $nodeId && (microtime(true) - $lastBeatAt) >= 5.0) {
                $connection->executeStatement(
                    'UPDATE swarm_node SET last_heartbeat_at = :now WHERE id = :id',
                    ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $nodeId],
                );
                $lastBeatAt = microtime(true);
            }

            $order = $this->claimNextOrder($node);
            if (null !== $order) {
                return $order;
            }
        }

        $this->heartbeat($node);

        return null;
    }

    /**
     * @param list<string> $files
     */
    public function reportProgress(
        ?SwarmNode $node,
        SwarmOrder $order,
        string $message,
        array $files = [],
    ): void {
        $trimmedMessage = trim($message);
        $order->markRunning();
        $order->mergeFilesTouched($files);

        $session = $node?->getSession();
        if (null !== $node) {
            $node->markBusy();
            if (null !== $session) {
                $this->sessionRecorder->recordEvent(
                    $session,
                    AgentSessionEventKind::Step,
                    \sprintf('[%s] %s', $order->getPublicId(), $trimmedMessage),
                    $files,
                    ['order_id' => $order->getPublicId()],
                );
            }
        }

        $body = \sprintf(
            '[%s] %s%s',
            $order->getTitle(),
            $trimmedMessage,
            [] !== $files ? "\nFichiers : ".implode(', ', $files) : '',
        );

        $this->persistBoardMessage(
            $order->getSwarm(),
            $node?->getName() ?? 'external',
            SwarmMessageKind::Progress,
            $body,
            $session,
        );
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $filesTouched
     */
    public function completeOrder(
        ?SwarmNode $node,
        SwarmOrder $order,
        bool $succeeded,
        string $summary,
        ?string $output = null,
        array $filesTouched = [],
        ?string $branch = null,
    ): void {
        if ($succeeded) {
            $order->complete($summary, $output, $filesTouched, $branch);
        } else {
            $order->fail($summary, $output, $filesTouched);
        }

        $session = $node?->getSession();
        if (null !== $node) {
            if (null !== $branch && '' !== trim($branch)) {
                $node->setBranch($branch);
            }
            $node->markIdle();

            if (null !== $session) {
                if (null !== $branch && '' !== trim($branch)) {
                    $session->setBranch($branch);
                }
                $this->sessionRecorder->recordEvent(
                    $session,
                    $succeeded ? AgentSessionEventKind::Completed : AgentSessionEventKind::Error,
                    \sprintf('[%s] %s', $order->getPublicId(), trim($summary)),
                    $order->getFilesTouched(),
                    array_filter([
                        'order_id' => $order->getPublicId(),
                        'status' => $order->getStatus()->value,
                        'branch' => $order->getBranch(),
                    ], static fn (mixed $v): bool => null !== $v && '' !== $v),
                );
            }
        }

        $files = $order->getFilesTouched();
        $body = \sprintf(
            '[%s · %s] %s%s',
            $succeeded ? 'OK' : 'ÉCHEC',
            $order->getTitle(),
            trim($summary),
            [] !== $files ? "\nFichiers : ".implode(', ', $files) : '',
        );

        $this->persistBoardMessage(
            $order->getSwarm(),
            $node?->getName() ?? 'external',
            $succeeded ? SwarmMessageKind::Progress : SwarmMessageKind::Review,
            $body,
            $session,
        );
        $this->entityManager->flush();
    }

    /**
     * Converts structured plan tasks into pending SwarmOrder rows for external Hub nodes.
     *
     * @return list<SwarmOrder>
     */
    public function publishPlanAsOrders(SwarmRun $swarm): array
    {
        $orders = [];
        foreach ($swarm->getPlan() as $task) {
            $orders[] = $this->createOrder(
                swarm: $swarm,
                kind: SwarmOrderKind::Edit,
                title: $task['title'],
                instructions: $task['instructions'],
                targetNode: null,
                filesHint: $task['files_hint'],
                ruleCode: $swarm->getSourceRuleCode(),
                createdBy: $swarm->getCreatedBy() ?? 'planner',
            );
        }

        return $orders;
    }

    public function cancelPendingOrders(SwarmRun $swarm): void
    {
        $pending = $this->orderRepository->pendingForSwarm($swarm);
        if ([] === $pending) {
            return;
        }

        foreach ($pending as $order) {
            $order->cancel();
        }

        $this->entityManager->flush();
    }

    private function persistBoardMessage(
        SwarmRun $swarm,
        string $author,
        SwarmMessageKind $kind,
        string $body,
        ?AgentSession $session = null,
    ): SwarmMessage {
        $message = new SwarmMessage($swarm, $author, $kind, $body, $session);
        $this->entityManager->persist($message);

        return $message;
    }
}
