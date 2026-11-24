<?php

namespace App\Controller\Connector;

use App\Connector\ConnectorRunner;
use App\Entity\Connector;
use App\Forge\GitHubApp;
use App\Llm\LlmSettings;
use App\Security\OAuth\OAuthClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/connectors/{id}/inspect', name: 'connector_inspect', requirements: ['id' => '\d+'], methods: ['POST'])]
final class InspectConnectorAction extends AbstractController
{
    public function __construct(
        private readonly ConnectorRunner $runner,
        private readonly OAuthClient $oauthClient,
        private readonly GitHubApp $gitHubApp,
        private readonly LlmSettings $llmSettings,
    ) {
    }

    public function __invoke(Connector $connector, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            $inspection = $this->runner->inspect($connector);
            $error = null;
        } catch (\Throwable $exception) {
            $inspection = null;
            $error = $exception->getMessage();
        }

        return $this->render('connector/show.html.twig', [
            'connector' => $connector,
            'inspection' => $inspection,
            'inspection_error' => $error,
            'oauth' => ConnectorOAuthState::isGitHubOAuthAvailable($connector, $this->oauthClient),
            'github_app' => $this->gitHubApp,
            'default_review_model' => $this->llmSettings->chatModel($connector->getProject()),
        ]);
    }
}
