<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\SwarmRun;
use App\Enum\AgentRunnerKind;
use App\Enum\SwarmNodeStatus;
use App\Enum\SwarmOrderKind;
use App\Enum\SwarmOrderStatus;
use App\Enum\SwarmStatus;
use App\Enum\SwarmStrategy;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmNodeRepository;
use App\Repository\SwarmOrderRepository;
use App\Repository\SwarmRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SwarmHubControllerTest extends WebTestCase
{
    public function testBriefAndOrderBriefRequireValidTokenAndServeMarkdown(): void
    {
        $client = self::createClient();
        [$swarm, $token, $hubManager] = $this->createRunningHubSwarm();

        $order = $hubManager->createOrder(
            swarm: $swarm,
            kind: SwarmOrderKind::Research,
            title: 'Auditer les routes Hub',
            instructions: 'Vérifier les headers no-store et no-referrer sur chaque endpoint.',
            filesHint: ['src/Factory/Hub/SwarmHubAuthenticator.php'],
            ruleCode: 'RG-SEC-01',
        );

        // Missing token -> 401
        $client->request('GET', '/hub/swarms/'.$swarm->getPublicId().'/brief');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Invalid token -> 401
        $client->request('GET', '/hub/swarms/'.$swarm->getPublicId().'/brief?token=wrong_token');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Valid query token serves Markdown brief with security headers
        $client->request('GET', '/hub/swarms/'.$swarm->getPublicId().'/brief?token='.$token);
        self::assertResponseIsSuccessful();
        $briefResponse = $client->getResponse();
        self::assertStringContainsString('text/markdown', (string) $briefResponse->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $briefResponse->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $briefResponse->headers->get('Referrer-Policy'));
        $briefBody = (string) $briefResponse->getContent();
        self::assertStringContainsString('Hub Live Test', $briefBody);
        self::assertStringContainsString($order->getPublicId(), $briefBody);

        // Valid Bearer token serves per-order Markdown brief
        $client->request(
            'GET',
            '/hub/swarms/'.$swarm->getPublicId().'/orders/'.$order->getPublicId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();
        $orderBriefResponse = $client->getResponse();
        self::assertStringContainsString('text/markdown', (string) $orderBriefResponse->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $orderBriefResponse->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $orderBriefResponse->headers->get('Referrer-Policy'));
        $orderBriefBody = (string) $orderBriefResponse->getContent();
        self::assertStringContainsString('Auditer les routes Hub', $orderBriefBody);
        self::assertStringContainsString('RG-SEC-01', $orderBriefBody);
    }

    public function testFullHttpNodeLifecycleClaimProgressCompleteAndDisconnect(): void
    {
        $client = self::createClient();
        [$swarm, $token] = $this->createRunningHubSwarm();
        $swarmId = $swarm->getPublicId();
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ];

        // 1. Two nodes join via POST /nodes/join
        $node1 = $this->jsonRequest($client, 'POST', '/hub/swarms/'.$swarmId.'/nodes/join', [
            'name' => 'claude-laptop',
            'client' => 'claude-code',
            'role' => 'developer',
            'branch' => 'feat/hub',
            'capabilities' => ['git', 'phpunit'],
        ], $headers, Response::HTTP_CREATED);

        self::assertStringStartsWith('node_', (string) $node1['node_id']);
        self::assertStringStartsWith('sess_', (string) $node1['session_id']);
        self::assertSame($swarmId, $node1['swarm_id']);
        self::assertSame('running', $node1['swarm_status']);
        self::assertIsArray($node1['project']);
        self::assertSame('demo', $node1['project']['slug']);
        self::assertStringContainsString('Demo', (string) $node1['project_context']);
        self::assertSame([], $node1['pending_orders']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));

        $node2 = $this->jsonRequest($client, 'POST', '/hub/swarms/'.$swarmId.'/nodes/join', [
            'name' => 'cursor-desktop',
            'client' => 'cursor',
            'role' => 'reviewer',
        ], $headers, Response::HTTP_CREATED);

        $node1Id = (string) $node1['node_id'];
        $node2Id = (string) $node2['node_id'];

        // 2. Dispatch an open work order (fetch fresh services from post-request container)
        /** @var SwarmRunRepository $swarmRepo */
        $swarmRepo = self::getContainer()->get(SwarmRunRepository::class);
        /** @var SwarmHubManager $hubManager */
        $hubManager = self::getContainer()->get(SwarmHubManager::class);
        $freshSwarm = $swarmRepo->findByPublicId($swarmId);
        self::assertNotNull($freshSwarm);

        $order = $hubManager->createOrder(
            swarm: $freshSwarm,
            kind: SwarmOrderKind::Edit,
            title: 'Implémenter endpoint wait',
            instructions: 'Retourner order, idle ou closed selon l’état.',
            filesHint: ['src/Controller/Factory/Hub/HubWaitOrderAction.php'],
        );
        $orderId = $order->getPublicId();

        // 3. Node 1 claims atomically; Node 2 immediately receives idle
        $wait1 = $this->jsonRequest(
            $client,
            'GET',
            '/hub/swarms/'.$swarmId.'/orders/wait?node='.$node1Id.'&timeout=0',
            null,
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertSame('order', $wait1['status']);
        self::assertSame('running', $wait1['swarm_status']);
        self::assertIsArray($wait1['order']);
        self::assertSame($orderId, $wait1['order']['order_id']);
        self::assertSame('edit', $wait1['order']['kind']);
        self::assertSame('Implémenter endpoint wait', $wait1['order']['title']);

        $wait2 = $this->jsonRequest(
            $client,
            'GET',
            '/hub/swarms/'.$swarmId.'/orders/wait?node='.$node2Id.'&timeout=0',
            null,
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertSame('idle', $wait2['status']);
        self::assertSame('running', $wait2['swarm_status']);

        // 4. Node 1 reports progress
        $progress = $this->jsonRequest(
            $client,
            'POST',
            '/hub/swarms/'.$swarmId.'/orders/'.$orderId.'/progress',
            [
                'nodeId' => $node1Id,
                'message' => 'Contrôleur créé, ajout des tests HTTP.',
                'files' => ['src/Controller/Factory/Hub/HubWaitOrderAction.php'],
            ],
            $headers,
        );
        self::assertSame($orderId, $progress['order_id']);
        self::assertSame('running', $progress['status']);
        self::assertSame(['src/Controller/Factory/Hub/HubWaitOrderAction.php'], $progress['files_touched']);

        // 5. Node 1 completes order
        $completed = $this->jsonRequest(
            $client,
            'POST',
            '/hub/swarms/'.$swarmId.'/orders/'.$orderId.'/complete',
            [
                'nodeId' => $node1Id,
                'status' => 'completed',
                'summary' => 'Endpoint wait terminé et testé.',
                'output' => 'Tous les tests HTTP passent.',
                'files' => ['tests/Controller/SwarmHubControllerTest.php'],
                'branch' => 'feat/hub-http',
            ],
            $headers,
        );
        self::assertSame($orderId, $completed['order_id']);
        self::assertSame('completed', $completed['status']);
        self::assertEqualsCanonicalizing(
            ['src/Controller/Factory/Hub/HubWaitOrderAction.php', 'tests/Controller/SwarmHubControllerTest.php'],
            $completed['files_touched'],
        );

        // 6. Node 1 disconnects cleanly
        $disconnected = $this->jsonRequest(
            $client,
            'POST',
            '/hub/swarms/'.$swarmId.'/nodes/'.$node1Id.'/disconnect',
            null,
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertSame($node1Id, $disconnected['node_id']);
        self::assertSame('offline', $disconnected['status']);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        /** @var SwarmOrderRepository $orderRepo */
        $orderRepo = self::getContainer()->get(SwarmOrderRepository::class);
        $persistedOrder = $orderRepo->findByPublicId($orderId);
        self::assertNotNull($persistedOrder);
        self::assertSame(SwarmOrderStatus::Completed, $persistedOrder->getStatus());
        self::assertSame('feat/hub-http', $persistedOrder->getBranch());

        /** @var SwarmNodeRepository $nodeRepo */
        $nodeRepo = self::getContainer()->get(SwarmNodeRepository::class);
        $persistedNode = $nodeRepo->findByPublicId($node1Id);
        self::assertNotNull($persistedNode);
        self::assertSame(SwarmNodeStatus::Offline, $persistedNode->getStatus());
    }

    public function testRevokedTokenAndCancelledSwarmRejectRequests(): void
    {
        $client = self::createClient();
        [$swarm, $token, $hubManager] = $this->createRunningHubSwarm();
        $swarmId = $swarm->getPublicId();

        // Revoking token rejects with 401
        $hubManager->revokeHubToken($swarm);
        $client->request('GET', '/hub/swarms/'.$swarmId.'/brief', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Re-enable token using fresh services from current container, verify access works
        /** @var SwarmRunRepository $swarmRepo */
        $swarmRepo = self::getContainer()->get(SwarmRunRepository::class);
        /** @var SwarmHubManager $freshHubManager */
        $freshHubManager = self::getContainer()->get(SwarmHubManager::class);
        $freshSwarm = $swarmRepo->findByPublicId($swarmId);
        self::assertNotNull($freshSwarm);

        $newToken = $freshHubManager->enableHubToken($freshSwarm);
        $client->request('GET', '/hub/swarms/'.$swarmId.'/brief', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$newToken]);
        self::assertResponseIsSuccessful();

        // Cancel swarm using fresh container after reboot -> rejects token with 401
        /** @var SwarmRunRepository $swarmRepoAfterRequest */
        $swarmRepoAfterRequest = self::getContainer()->get(SwarmRunRepository::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $swarmToCancel = $swarmRepoAfterRequest->findByPublicId($swarmId);
        self::assertNotNull($swarmToCancel);
        $swarmToCancel->cancel('Session terminée.');
        $em->flush();

        $client->request('GET', '/hub/swarms/'.$swarmId.'/brief', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$newToken]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUiHubFreeModeLaunchDispatchOrdersStateCancelAndTokenLifecycle(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE swarm_order, swarm_node, swarm_message, swarm_run, agent_session_event, agent_session, user_preference, project RESTART IDENTITY CASCADE');

        $project = new Project('hubui', 'Hub UI', '/tmp/hub-ui');
        $em->persist($project);
        $em->flush();

        // 1. Launch external_hub swarm with skip_initial_plan=1 (Hub libre)
        $crawler = $client->request('GET', '/projects/hubui/factory');
        self::assertResponseIsSuccessful();
        $launchToken = (string) $crawler->filter('#factory-new-swarm input[name="_token"]')->attr('value');

        $client->request('POST', '/projects/hubui/factory/swarms', [
            '_token' => $launchToken,
            'title' => 'Hub Libre Direct',
            'goal' => 'Piloter deux clients IDE en direct.',
            'strategy' => 'parallel',
            'worker_runner' => 'external_hub',
            'skip_initial_plan' => '1',
            'require_plan_approval' => '1',
            'run_sync' => '1',
        ]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hub Libre Direct');
        // Connect panel is open with Stimulus controller and URL join prompt
        self::assertSelectorExists('[data-controller="swarm-hub"]');
        self::assertSelectorExists('details.hub-connect[open]');

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $swarm = $em->getRepository(SwarmRun::class)->findOneBy(['title' => 'Hub Libre Direct']);
        self::assertNotNull($swarm);
        self::assertTrue($swarm->isHubMode());
        self::assertSame(SwarmStatus::Running, $swarm->getStatus());
        self::assertTrue($swarm->hasHubToken());

        /** @var SwarmHubManager $hubManager */
        $hubManager = self::getContainer()->get(SwarmHubManager::class);
        $initialToken = $hubManager->resolveHubToken($swarm);
        self::assertNotNull($initialToken);
        $swarmId = $swarm->getPublicId();

        // Join two nodes so we can test targeted dispatch and broadcast
        $nodeA = $hubManager->joinNode($swarm, 'claude-mac', 'claude-code', 'developer', 'feat/a');
        $nodeB = $hubManager->joinNode($swarm, 'cursor-win', 'cursor', 'reviewer', 'feat/b');
        $nodeAId = $nodeA->getPublicId();
        $nodeBId = $nodeB->getPublicId();

        // Refresh swarm page to grab CSRF tokens
        $crawler = $client->request('GET', '/factory/swarms/'.$swarmId);
        self::assertResponseIsSuccessful();
        $orderCsrf = (string) $crawler->filter('form[action$="/orders"] input[name="_token"]')->attr('value');
        $hubTokenCsrf = (string) $crawler->filter('form[action$="/hub-token"] input[name="_token"]')->first()->attr('value');

        // 2. Dispatch untargeted order (target=any)
        $client->request('POST', '/factory/swarms/'.$swarmId.'/orders', [
            '_token' => $orderCsrf,
            'kind' => 'research',
            'target' => 'any',
            'title' => 'Ordre ouvert',
            'instructions' => 'Explorer le module billing.',
            'files_hint' => 'src/Billing/Service.php, tests/BillingTest.php',
            'rule_code' => 'RG-010',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarmId);

        // 3. Dispatch targeted order to nodeA
        $client->request('POST', '/factory/swarms/'.$swarmId.'/orders', [
            '_token' => $orderCsrf,
            'kind' => 'edit',
            'target' => $nodeAId,
            'title' => 'Ordre ciblé Claude',
            'instructions' => 'Corriger le calcul TVA.',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarmId);

        // 4. Broadcast order to all connected nodes (target=all -> creates 2 targeted orders)
        $client->request('POST', '/factory/swarms/'.$swarmId.'/orders', [
            '_token' => $orderCsrf,
            'kind' => 'review',
            'target' => 'all',
            'title' => 'Revue croisée',
            'instructions' => 'Relire le diff complet.',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarmId);

        // 5. GET /factory/swarms/{id}/state returns expected JSON structure
        $state = $this->jsonRequest($client, 'GET', '/factory/swarms/'.$swarmId.'/state');
        self::assertSame('running', $state['status']);
        self::assertTrue($state['is_active']);
        self::assertIsArray($state['counts']);
        self::assertSame(2, $state['counts']['nodes_online']);
        self::assertSame(4, $state['counts']['orders_pending']);
        self::assertSame(0, $state['counts']['orders_running']);
        self::assertSame(0, $state['counts']['orders_completed']);
        self::assertIsArray($state['nodes']);
        self::assertCount(2, $state['nodes']);
        self::assertIsArray($state['orders']);
        self::assertCount(4, $state['orders']);
        self::assertIsArray($state['messages']);
        self::assertNotEmpty($state['messages']);

        // Verify order fields (direct_url, cancel_url, cancel_csrf, targets)
        $orderToCancel = null;
        $targetedCount = 0;
        foreach ($state['orders'] as $orderRow) {
            self::assertIsArray($orderRow);
            self::assertIsString($orderRow['direct_url']);
            self::assertStringContainsString('/hub/swarms/'.$swarmId.'/orders/'.$orderRow['public_id'], $orderRow['direct_url']);
            self::assertIsString($orderRow['cancel_url']);
            self::assertIsString($orderRow['cancel_csrf']);
            if ('Ordre ouvert' === $orderRow['title']) {
                self::assertNull($orderRow['target_node']);
                self::assertSame(['src/Billing/Service.php', 'tests/BillingTest.php'], $orderRow['files_hint']);
                self::assertSame('RG-010', $orderRow['rule_code']);
                $orderToCancel = $orderRow;
            }
            if (null !== $orderRow['target_node']) {
                ++$targetedCount;
            }
        }
        self::assertNotNull($orderToCancel);
        // 1 explicitly targeted to nodeA + 2 broadcast orders (one for nodeA, one for nodeB)
        self::assertSame(3, $targetedCount);

        // 6. POST /factory/swarms/{id}/orders/{orderPublicId}/cancel cancels a pending order
        $client->request('POST', (string) $orderToCancel['cancel_url'], [
            '_token' => (string) $orderToCancel['cancel_csrf'],
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarmId);

        /** @var SwarmOrderRepository $orderRepo */
        $orderRepo = self::getContainer()->get(SwarmOrderRepository::class);
        $cancelledOrder = $orderRepo->findByPublicId((string) $orderToCancel['public_id']);
        self::assertNotNull($cancelledOrder);
        self::assertSame(SwarmOrderStatus::Cancelled, $cancelledOrder->getStatus());

        // 7. POST /factory/swarms/{id}/hub-token: rotate -> revoke -> enable
        $client->request('POST', '/factory/swarms/'.$swarmId.'/hub-token', [
            '_token' => $hubTokenCsrf,
            'action' => 'rotate',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarmId);

        // Old token is now rejected
        $client->request('GET', '/hub/swarms/'.$swarmId.'/brief?token='.$initialToken);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        /** @var SwarmRunRepository $swarmRepoAfterRotate */
        $swarmRepoAfterRotate = self::getContainer()->get(SwarmRunRepository::class);
        /** @var SwarmHubManager $hubManagerAfterRotate */
        $hubManagerAfterRotate = self::getContainer()->get(SwarmHubManager::class);
        $rotatedSwarm = $swarmRepoAfterRotate->findByPublicId($swarmId);
        self::assertNotNull($rotatedSwarm);
        $rotatedToken = $hubManagerAfterRotate->resolveHubToken($rotatedSwarm);
        self::assertNotNull($rotatedToken);
        self::assertNotSame($initialToken, $rotatedToken);

        // New rotated token works
        $client->request('GET', '/hub/swarms/'.$swarmId.'/brief?token='.$rotatedToken);
        self::assertResponseIsSuccessful();

        // Revoke token -> 401
        $client->request('POST', '/factory/swarms/'.$swarmId.'/hub-token', [
            '_token' => $hubTokenCsrf,
            'action' => 'revoke',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarmId);

        $client->request('GET', '/hub/swarms/'.$swarmId.'/brief?token='.$rotatedToken);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Enable token -> fresh working token restored
        $client->request('POST', '/factory/swarms/'.$swarmId.'/hub-token', [
            '_token' => $hubTokenCsrf,
            'action' => 'enable',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarmId);

        /** @var SwarmRunRepository $swarmRepoAfterEnable */
        $swarmRepoAfterEnable = self::getContainer()->get(SwarmRunRepository::class);
        /** @var SwarmHubManager $hubManagerAfterEnable */
        $hubManagerAfterEnable = self::getContainer()->get(SwarmHubManager::class);
        $reenabledSwarm = $swarmRepoAfterEnable->findByPublicId($swarmId);
        self::assertNotNull($reenabledSwarm);
        $reenabledToken = $hubManagerAfterEnable->resolveHubToken($reenabledSwarm);
        self::assertNotNull($reenabledToken);

        $client->request('GET', '/hub/swarms/'.$swarmId.'/brief?token='.$reenabledToken);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('status != closed', (string) $client->getResponse()->getContent());
        self::assertStringContainsString($nodeBId, (string) json_encode($state['nodes'], \JSON_THROW_ON_ERROR));
    }

    public function testUiHubPlannedModeConvertsApprovedPlanIntoSwarmOrders(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE swarm_order, swarm_node, swarm_message, swarm_run, agent_session_event, agent_session, user_preference, project RESTART IDENTITY CASCADE');

        $project = new Project('hubplan', 'Hub Plan', '/tmp/hub-plan');
        $em->persist($project);
        $em->flush();

        // 1. Launch external_hub swarm WITH initial planning (skip_initial_plan omitted)
        $crawler = $client->request('GET', '/projects/hubplan/factory');
        self::assertResponseIsSuccessful();
        $launchToken = (string) $crawler->filter('#factory-new-swarm input[name="_token"]')->attr('value');

        $client->request('POST', '/projects/hubplan/factory/swarms', [
            '_token' => $launchToken,
            'title' => 'Hub Planifié',
            'goal' => 'Décomposer la migration auth en ordres Hub.',
            'strategy' => 'pipeline',
            'worker_runner' => 'external_hub',
            'require_plan_approval' => '1',
            'run_sync' => '1',
        ]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $swarm = $em->getRepository(SwarmRun::class)->findOneBy(['title' => 'Hub Planifié']);
        self::assertNotNull($swarm);
        self::assertTrue($swarm->isHubMode());
        self::assertSame(SwarmStatus::AwaitingApproval, $swarm->getStatus());
        self::assertCount(2, $swarm->getPlan());

        // 2. Approve plan -> converts plan items into SwarmOrder rows and stays running for external Hub nodes
        $approveToken = (string) $crawler->filter('form[action$="/approve"] input[name="_token"]')->attr('value');
        $client->request('POST', '/factory/swarms/'.$swarm->getPublicId().'/approve', [
            '_token' => $approveToken,
            'run_sync' => '1',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarm->getPublicId());
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        /** @var SwarmRunRepository $swarmRepo */
        $swarmRepo = self::getContainer()->get(SwarmRunRepository::class);
        /** @var SwarmOrderRepository $orderRepo */
        $orderRepo = self::getContainer()->get(SwarmOrderRepository::class);
        $approvedSwarm = $swarmRepo->findByPublicId($swarm->getPublicId());
        self::assertNotNull($approvedSwarm);
        self::assertSame(SwarmStatus::Running, $approvedSwarm->getStatus());
        self::assertTrue($approvedSwarm->hasHubToken());

        $orders = $orderRepo->forSwarm($approvedSwarm);
        self::assertCount(2, $orders);
        foreach ($orders as $order) {
            self::assertSame(SwarmOrderStatus::Pending, $order->getStatus());
        }
    }

    public function testChatDispatchAutoTitleCodeMirrorOutputAndEndSession(): void
    {
        $client = self::createClient();
        [$swarm, $token, $hubManager] = $this->createRunningHubSwarm();
        $swarmId = $swarm->getPublicId();

        $node = $hubManager->joinNode($swarm, 'Antigravity · symfony-ai', 'antigravity', 'developer', 'main');
        $nodeId = $node->getPublicId();

        $crawler = $client->request('GET', '/factory/swarms/'.$swarmId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.swarm-presence.is-online');
        self::assertSelectorExists('[data-swarm-hub-target="composerInput"]');
        $orderCsrf = (string) $crawler->filter('form[action$="/orders"] input[name="_token"]')->attr('value');
        $controlCsrf = (string) $crawler->filter('form[action$="/control"] input[name="_token"]')->first()->attr('value');

        // Dispatch via chat JSON without explicit title (auto-derived from first line of instructions)
        $client->request('POST', '/factory/swarms/'.$swarmId.'/orders', [
            '_token' => $orderCsrf,
            'target' => $nodeId,
            'kind' => 'custom',
            'instructions' => "Propose la maquette du chatbot\nAvec /all et /end.",
        ], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $dispatched = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $dispatched['orders']);
        self::assertSame('Propose la maquette du chatbot', $dispatched['orders'][0]['title']);
        $orderId = (string) $dispatched['orders'][0]['public_id'];

        // Complete the order with Markdown output and verify CodeMirror viewer renders in chat
        $this->jsonRequest($client, 'POST', '/hub/swarms/'.$swarmId.'/orders/'.$orderId.'/complete', [
            'nodeId' => $nodeId,
            'status' => 'completed',
            'summary' => 'Maquette livrée.',
            'output' => "# Maquette Chatbot\n\n- `/all`\n- `/agent`\n- `/end`",
        ], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        $client->request('GET', '/factory/swarms/'.$swarmId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.swarm-cm[data-controller="code-viewer"]');
        self::assertSelectorTextContains('.swarm-cm__fallback', '# Maquette Chatbot');

        // /end completes the swarm cleanly, disconnects active nodes, and returns JSON
        $client->request('POST', '/factory/swarms/'.$swarmId.'/control', [
            '_token' => $controlCsrf,
            'action' => 'complete',
            'summary' => 'Session terminée avec succès.',
        ], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $ended = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('completed', $ended['status']);
        self::assertFalse($ended['is_active']);

        /** @var SwarmRunRepository $swarmRepo */
        $swarmRepo = self::getContainer()->get(SwarmRunRepository::class);
        /** @var SwarmNodeRepository $nodeRepo */
        $nodeRepo = self::getContainer()->get(SwarmNodeRepository::class);
        $finishedSwarm = $swarmRepo->findByPublicId($swarmId);
        self::assertNotNull($finishedSwarm);
        self::assertSame(SwarmStatus::Completed, $finishedSwarm->getStatus());
        self::assertSame('Session terminée avec succès.', $finishedSwarm->getReviewSummary());
        $disconnectedNode = $nodeRepo->findByPublicId($nodeId);
        self::assertNotNull($disconnectedNode);
        self::assertSame(SwarmNodeStatus::Offline, $disconnectedNode->getStatus());
    }

    /**
     * @return array{SwarmRun, string, SwarmHubManager}
     */
    private function createRunningHubSwarm(): array
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE swarm_order, swarm_node, swarm_message, swarm_run, agent_session_event, agent_session, project RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo', '/tmp/demo');
        $swarm = new SwarmRun(
            project: $project,
            title: 'Hub Live Test',
            goal: 'Coordonner des clients externes en temps réel.',
            strategy: SwarmStrategy::Parallel,
            workerRunner: AgentRunnerKind::ExternalHub,
            requirePlanApproval: false,
            hubMode: true,
        );
        $swarm->markRunning();
        $em->persist($project);
        $em->persist($swarm);
        $em->flush();

        /** @var SwarmHubManager $hubManager */
        $hubManager = self::getContainer()->get(SwarmHubManager::class);
        $token = $hubManager->enableHubToken($swarm);

        return [$swarm, $token, $hubManager];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $server
     *
     * @return array<string, mixed>
     */
    private function jsonRequest(
        KernelBrowser $client,
        string $method,
        string $uri,
        ?array $body = null,
        array $server = [],
        int $expectedStatus = Response::HTTP_OK,
    ): array {
        $content = null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR);
        $client->request($method, $uri, server: $server, content: $content);
        self::assertResponseStatusCodeSame($expectedStatus);

        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
