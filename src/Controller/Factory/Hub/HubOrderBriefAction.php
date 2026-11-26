<?php

namespace App\Controller\Factory\Hub;

use App\Factory\Hub\SwarmHubAuthenticator;
use App\Factory\Swarm\SwarmBriefGenerator;
use App\Repository\SwarmOrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a single work order as a self-contained Markdown brief for one-shot external agents.
 */
final class HubOrderBriefAction extends AbstractController
{
    public function __construct(
        private readonly SwarmHubAuthenticator $authenticator,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly SwarmBriefGenerator $briefGenerator,
    ) {
    }

    #[Route('/hub/swarms/{publicId}/orders/{orderPublicId}', name: 'hub_swarm_order_brief', methods: ['GET'])]
    public function __invoke(Request $request, string $publicId, string $orderPublicId): Response
    {
        $swarm = $this->authenticator->authenticate($request, $publicId);
        $order = $this->orderRepository->findByPublicId($orderPublicId);
        if (null === $order || $order->getSwarm()->getId() !== $swarm->getId()) {
            throw $this->createNotFoundException('Order not found.');
        }

        $token = $this->authenticator->extractToken($request) ?? '';
        $markdown = $this->briefGenerator->orderBriefMarkdown($swarm, $order, $token);

        $response = new Response($markdown, Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
        ]);

        return $this->authenticator->applySecurityHeaders($response);
    }
}
