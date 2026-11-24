<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Security\OAuth\OAuthClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/connectors/{id}/oauth', name: 'connector_oauth', requirements: ['id' => '\d+'], methods: ['POST'])]
final class StartGitHubOAuthAction extends AbstractController
{
    public function __construct(
        private readonly OAuthClient $oauthClient,
    ) {
    }

    public function __invoke(Connector $connector, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        if (!ConnectorOAuthState::isGitHubOAuthAvailable($connector, $this->oauthClient)) {
            throw $this->createNotFoundException('GitHub OAuth is not available for this connector.');
        }
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(ConnectorOAuthState::GITHUB_OAUTH, ['state' => $state, 'connector' => $connector->getId()]);

        return $this->redirect($this->oauthClient->authorizeUrl('github', $this->generateUrl('connector_oauth_check', [], UrlGeneratorInterface::ABSOLUTE_URL), $state, 'repo'));
    }
}
