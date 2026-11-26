<?php

namespace App\Controller\Factory\Hub;

use App\Factory\Dto\HubOrderProgressPayload;
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
 * Records an intermediate progress milestone and touched files for an active Swarm Hub work order.
 */
final class HubReportOrderAction extends AbstractController
{
    public function __construct(
        private readonly SwarmHubAuthenticator $authenticator,
        private readonly SwarmHubManager $hubManager,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly SwarmNodeRepository $nodeRepository,
    ) {
    }

    #[Route('/hub/swarms/{publicId}/orders/{orderPublicId}/progress', name: 'hub_swarm_report_order', methods: ['POST'])]
    public function __invoke(
        Request $request,
        string $publicId,
        string $orderPublicId,
        #[MapRequestPayload] HubOrderProgressPayload $payload,
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

        $this->hubManager->reportProgress($node, $order, $payload->message, $payload->files);

        return $this->authenticator->applySecurityHeaders($this->json([
            'order_id' => $order->getPublicId(),
            'status' => $order->getStatus()->value,
            'files_touched' => $order->getFilesTouched(),
        ]));
    }
}
