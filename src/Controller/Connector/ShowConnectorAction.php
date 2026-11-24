<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Forge\GitHubApp;
use App\Llm\LlmSettings;
use App\Security\OAuth\OAuthClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/connectors/{id}', name: 'connector_show', requirements: ['id' => '\d+'], methods: ['GET'])]
final class ShowConnectorAction extends AbstractController
{
    public function __construct(
        private readonly OAuthClient $oauthClient,
        private readonly GitHubApp $gitHubApp,
        private readonly LlmSettings $llmSettings,
    ) {
    }

    public function __invoke(Connector $connector): Response
    {
        return $this->render('connector/show.html.twig', [
            'connector' => $connector,
            'inspection' => null,
            'inspection_error' => null,
            'oauth' => ConnectorOAuthState::isGitHubOAuthAvailable($connector, $this->oauthClient),
            'github_app' => $this->gitHubApp,
            'default_review_model' => $this->llmSettings->chatModel($connector->getProject()),
        ]);
    }
}
