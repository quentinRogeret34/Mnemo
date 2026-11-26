<?php

namespace App\Controller\Factory\Hub;

use App\Factory\Hub\SwarmHubAuthenticator;
use App\Factory\Swarm\SwarmBriefGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the signed Markdown onboarding brief and HTTP/curl protocol for an active Swarm Hub.
 */
final class HubBriefAction extends AbstractController
{
    public function __construct(
        private readonly SwarmHubAuthenticator $authenticator,
        private readonly SwarmBriefGenerator $briefGenerator,
    ) {
    }

    #[Route('/hub/swarms/{publicId}/brief', name: 'hub_swarm_brief', methods: ['GET'])]
    public function __invoke(Request $request, string $publicId): Response
    {
        $swarm = $this->authenticator->authenticate($request, $publicId);
        $token = $this->authenticator->extractToken($request) ?? '';
        $markdown = $this->briefGenerator->swarmBriefMarkdown($swarm, $token);

        $response = new Response($markdown, Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
        ]);

        return $this->authenticator->applySecurityHeaders($response);
    }
}
