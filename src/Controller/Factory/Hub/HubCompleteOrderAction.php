<?php

namespace App\Controller\Factory\Hub;

use App\Factory\Dto\HubOrderCompletePayload;
use App\Factory\Hub\SwarmHubAuthenticator;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmNodeRepository;
use App\Repository\SwarmOrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Completes or fails a Swarm Hub work order and records its final summary, output, files and branch.
 */
final class HubCompleteOrderAction extends AbstractController
{
    public function __construct(
        private readonly SwarmHubAuthenticator $authenticator,
        private readonly SwarmHubManager $hubManager,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly SwarmNodeRepository $nodeRepository,
    ) {
    }

    #[Route('/hub/swarms/{publicId}/orders/{orderPublicId}/complete', name: 'hub_swarm_complete_order', methods: ['POST'])]
    public function __invoke(
        Request $request,
        string $publicId,
        string $orderPublicId,
        #[MapRequestPayload] HubOrderCompletePayload $payload,
    ): Response {
        $swarm = $this->authenticator->authenticate($request, $publicId);
        $order = $this->orderRepository->findByPublicId($orderPublicId);
        if (null === $order || $order->getSwarm()->getId() !== $swarm->getId()) {
            throw $this->createNotFoundException('Order not found.');
        }

        $node = null;
        if (null !== $payload->nodeId && '' !== trim($payload->nodeId)) {
            $node = $this->nodeRepository->findByPublicId($payload->nodeId);
            if (null === $node || $node->getSwarm()->getId() !== $swarm->getId()) {
                throw $this->createNotFoundException('Node not found.');
            }
        } else {
            $node = $order->getClaimedByNode();
        }

        $this->hubManager->completeOrder(
            node: $node,
            order: $order,
            succeeded: 'completed' === $payload->status,
            summary: $payload->summary,
            output: $payload->output,
            filesTouched: $payload->files,
            branch: $payload->branch,
        );

        return $this->authenticator->applySecurityHeaders($this->json([
            'order_id' => $order->getPublicId(),
            'status' => $order->getStatus()->value,
            'files_touched' => $order->getFilesTouched(),
        ]));
    }
}
