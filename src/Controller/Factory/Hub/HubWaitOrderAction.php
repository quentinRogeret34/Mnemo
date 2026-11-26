<?php

namespace App\Controller\Factory\Hub;

use App\Factory\Hub\SwarmHubAuthenticator;
use App\Factory\Swarm\ProjectContextBuilder;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmNodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Long-polls for the next claimable work order assigned to a connected Swarm Hub node.
 */
final class HubWaitOrderAction extends AbstractController
{
    public function __construct(
        private readonly SwarmHubAuthenticator $authenticator,
        private readonly SwarmHubManager $hubManager,
        private readonly SwarmNodeRepository $nodeRepository,
        private readonly ProjectContextBuilder $contextBuilder,
    ) {
    }

    #[Route('/hub/swarms/{publicId}/orders/wait', name: 'hub_swarm_wait_order', methods: ['GET'], priority: 10)]
    public function __invoke(Request $request, string $publicId): Response
    {
        $swarm = $this->authenticator->authenticate($request, $publicId);

        $nodePublicId = trim((string) $request->query->get('node', ''));
        if ('' === $nodePublicId) {
            return $this->authenticator->applySecurityHeaders(
                $this->json(['error' => 'Missing required "node" query parameter.'], Response::HTTP_BAD_REQUEST),
            );
        }

        $node = $this->nodeRepository->findByPublicId($nodePublicId);
        if (null === $node || $node->getSwarm()->getId() !== $swarm->getId()) {
            throw $this->createNotFoundException('Node not found.');
        }

        $timeout = $request->query->getInt('timeout', 20);
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }
        if (\PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        $order = $this->hubManager->waitForOrder($node, $timeout);

        if (null !== $order) {
            $payload = [
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
        } elseif (!$swarm->getStatus()->isActive()) {
            $payload = [
                'status' => 'closed',
                'swarm_status' => $swarm->getStatus()->value,
            ];
        } else {
            $payload = [
                'status' => 'idle',
                'swarm_status' => $swarm->getStatus()->value,
            ];
        }

        return $this->authenticator->applySecurityHeaders($this->json($payload));
    }
}
