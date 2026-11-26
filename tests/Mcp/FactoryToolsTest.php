<?php

namespace App\Tests\Mcp;

use App\Entity\Project;
use App\Entity\SwarmRun;
use App\Enum\AgentRunnerKind;
use App\Enum\SwarmOrderKind;
use App\Enum\SwarmOrderStatus;
use App\Enum\SwarmStrategy;
use App\Factory\Swarm\SwarmHubManager;
use App\Mcp\FactoryTools;
use App\Repository\SwarmNodeRepository;
use App\Repository\SwarmOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FactoryToolsTest extends KernelTestCase
{
    public function testMcpToolsTrackParentAndWorkerSessions(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE swarm_order, swarm_node, swarm_message, swarm_run, agent_session_event, agent_session, project RESTART IDENTITY CASCADE');

        $project = new Project('mnemo', 'Mnemo', '/tmp/mnemo');
        $em->persist($project);
        $em->flush();

        /** @var FactoryTools $tools */
        $tools = self::getContainer()->get(FactoryTools::class);

        $coordinator = $tools->startSession(
            project: 'mnemo',
            title: 'Ruche — refactor paiement',
            role: 'coordinator',
            goal: 'Coordonner 2 workers parallèles.',
            client: 'antigravity',
            branch: 'feat/payment-swarm',
            ruleCode: 'RG-042',
        );
        self::assertStringStartsWith('sess_', (string) $coordinator['session_id']);

        $worker = $tools->startSession(
            project: 'mnemo',
            title: 'Worker Backend Stripe',
            role: 'worker',
            parentSessionId: (string) $coordinator['session_id'],
            runner: 'claude_cli',
        );

        $logged = $tools->logSessionEvent(
            sessionId: (string) $worker['session_id'],
            kind: 'file_changed',
            message: 'Adapter Stripe implémenté.',
            files: ['src/Payment/StripeAdapter.php'],
        );
        self::assertSame(['src/Payment/StripeAdapter.php'], $logged['files_touched']);

        $ended = $tools->endSession(
            sessionId: (string) $worker['session_id'],
            status: 'succeeded',
            summary: 'Tests Stripe verts.',
        );
        self::assertSame('succeeded', $ended['status']);

        $listed = $tools->listSessions(project: 'mnemo', status: 'running');
        self::assertSame(1, $listed['count']);
        self::assertSame($coordinator['session_id'], $listed['sessions'][0]['session_id']);
    }

    public function testInteractiveSwarmHubMcpToolsFlow(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE swarm_order, swarm_node, swarm_message, swarm_run, agent_session_event, agent_session, project RESTART IDENTITY CASCADE');

        $project = new Project('mnemo', 'Mnemo', '/tmp/mnemo');
        $swarm = new SwarmRun(
            project: $project,
            title: 'Hub Live MCP',
            goal: 'Distribuer des ordres ciblés aux agents MCP.',
            strategy: SwarmStrategy::Parallel,
            workerRunner: AgentRunnerKind::ExternalHub,
            requirePlanApproval: false,
            hubMode: true,
        );
        $swarm->markRunning();
        $em->persist($project);
        $em->persist($swarm);
        $em->flush();

        /** @var FactoryTools $tools */
        $tools = self::getContainer()->get(FactoryTools::class);
        /** @var SwarmHubManager $hubManager */
        $hubManager = self::getContainer()->get(SwarmHubManager::class);
        /** @var SwarmNodeRepository $nodeRepository */
        $nodeRepository = self::getContainer()->get(SwarmNodeRepository::class);
        /** @var SwarmOrderRepository $orderRepository */
        $orderRepository = self::getContainer()->get(SwarmOrderRepository::class);

        $nodeA = $tools->joinSwarm(
            swarmId: $swarm->getPublicId(),
            name: 'cursor-front',
            client: 'cursor',
            role: 'frontend',
            branch: 'feat/ui',
        );
        $nodeB = $tools->joinSwarm(
            swarmId: $swarm->getPublicId(),
            name: 'claude-back',
            client: 'claude-code',
            role: 'backend',
            branch: 'feat/api',
        );

        self::assertStringStartsWith('node_', (string) $nodeA['node_id']);
        self::assertStringStartsWith('sess_', (string) $nodeA['session_id']);
        self::assertStringContainsString('Mnemo', (string) $nodeA['project_context']);

        $targetNode = $nodeRepository->findByPublicId((string) $nodeB['node_id']);
        self::assertNotNull($targetNode);

        $order = $hubManager->createOrder(
            swarm: $swarm,
            kind: SwarmOrderKind::Edit,
            title: 'Ajouter rate limiter Hub',
            instructions: 'Configurer un rate limiter sliding_window sur /hub/swarms.',
            targetNode: $targetNode,
            filesHint: ['config/packages/rate_limiter.yaml'],
            ruleCode: 'RG-HUB-01',
        );

        // Non-target node gets idle
        $waitA = $tools->waitSwarmOrder(
            swarmId: $swarm->getPublicId(),
            nodeId: (string) $nodeA['node_id'],
            timeout: 0,
        );
        self::assertSame('idle', $waitA['status']);

        // Target node claims the order
        $waitB = $tools->waitSwarmOrder(
            swarmId: $swarm->getPublicId(),
            nodeId: (string) $nodeB['node_id'],
            timeout: 0,
        );
        self::assertSame('order', $waitB['status']);
        self::assertIsArray($waitB['order']);
        self::assertSame($order->getPublicId(), $waitB['order']['order_id']);
        self::assertSame('RG-HUB-01', $waitB['order']['rule_code']);

        // Report progress
        $progress = $tools->reportSwarmOrder(
            swarmId: $swarm->getPublicId(),
            orderId: $order->getPublicId(),
            message: 'Rate limiter configuré, écriture des tests.',
            nodeId: (string) $nodeB['node_id'],
            files: ['config/packages/rate_limiter.yaml'],
        );
        self::assertSame('running', $progress['status']);
        self::assertSame(['config/packages/rate_limiter.yaml'], $progress['files_touched']);

        // Complete order
        $completed = $tools->completeSwarmOrder(
            swarmId: $swarm->getPublicId(),
            orderId: $order->getPublicId(),
            summary: 'Rate limiter et tests fonctionnels verts.',
            status: 'completed',
            output: "## Résultat\n- 120 req/min par IP",
            files: ['src/Factory/Hub/SwarmHubAuthenticator.php'],
            branch: 'feat/hub-api',
            nodeId: (string) $nodeB['node_id'],
        );
        self::assertSame('completed', $completed['status']);
        self::assertSame('feat/hub-api', $completed['branch']);
        self::assertEqualsCanonicalizing(
            ['config/packages/rate_limiter.yaml', 'src/Factory/Hub/SwarmHubAuthenticator.php'],
            $completed['files_touched'],
        );

        $em->clear();
        $reloadedOrder = $orderRepository->findByPublicId($order->getPublicId());
        self::assertNotNull($reloadedOrder);
        self::assertSame(SwarmOrderStatus::Completed, $reloadedOrder->getStatus());
        self::assertSame('Rate limiter et tests fonctionnels verts.', $reloadedOrder->getResultSummary());
    }
}
