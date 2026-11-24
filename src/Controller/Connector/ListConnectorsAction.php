<?php

namespace App\Controller\Connector;

use App\Connector\ConnectorPresets;
use App\Entity\Connector;
use App\Entity\Folder;
use App\Entity\Project;
use App\Llm\LlmSettings;
use App\Security\OAuth\OAuthClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/connectors', name: 'connector_index', methods: ['GET'])]
final class ListConnectorsAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LlmSettings $llmSettings,
        private readonly ConnectorPresets $presets,
        private readonly OAuthClient $oauthClient,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('connector/index.html.twig', [
            'connectors' => $this->entityManager->getRepository(Connector::class)->findBy([], ['name' => 'ASC']),
            'llm' => $this->llmSettings,
            'presets' => $this->presets->available(),
            'github_oauth' => isset($this->oauthClient->enabled()['github']),
            'projects' => $this->entityManager->getRepository(Project::class)->findBy(['archivedAt' => null], ['name' => 'ASC']),
            'folders' => $this->entityManager->getRepository(Folder::class)->findBy([], ['name' => 'ASC']),
        ]);
    }
}
