<?php

namespace App\Controller\Factory\Hub;

use App\Factory\Hub\SwarmHubAuthenticator;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmNodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cleanly disconnects an external worker node from an active Swarm Hub.
 */
final class HubDisconnectAction extends AbstractController
{
    public function __construct(
        private readonly SwarmHubAuthenticator $authenticator,
        private readonly SwarmHubManager $hubManager,
        private readonly SwarmNodeRepository $nodeRepository,
    ) {
    }

    #[Route('/hub/swarms/{publicId}/nodes/{nodePublicId}/disconnect', name: 'hub_swarm_disconnect', methods: ['POST'])]
    public function __invoke(Request $request, string $publicId, string $nodePublicId): Response
    {
        $swarm = $this->authenticator->authenticate($request, $publicId);
        $node = $this->nodeRepository->findByPublicId($nodePublicId);
        if (null === $node || $node->getSwarm()->getId() !== $swarm->getId()) {
            throw $this->createNotFoundException('Node not found.');
        }

        $this->hubManager->disconnectNode($node);

        return $this->authenticator->applySecurityHeaders($this->json([
            'node_id' => $node->getPublicId(),
            'status' => 'offline',
        ]));
    }
}
