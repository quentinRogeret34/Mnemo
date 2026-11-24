<?php

namespace App\Controller\Connector;

use App\Connector\ConnectorPresets;
use App\Connector\CredentialResolver;
use App\Connector\McpAuthorization;
use App\Entity\Connector;
use App\Entity\Folder;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * One-click guided connection for curated integrations (Atlassian/Linear OAuth, Notion encrypted token).
 */
#[Route('/connectors/connect/{preset}', name: 'connector_connect', methods: ['POST'])]
final class ConnectPresetAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorPresets $presets,
        private readonly CredentialResolver $credentials,
        private readonly McpAuthorization $authorization,
    ) {
    }

    public function __invoke(string $preset, Request $request): Response
    {
        $presetData = $this->presets->get($preset) ?? throw $this->createNotFoundException(\sprintf('Unknown preset "%s".', $preset));
        if (!$this->isCsrfTokenValid('connector-connect-'.$preset, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $payload = $request->getPayload();
        $connector = new Connector();
        $this->presets->apply($preset, $connector);

        $customName = trim($payload->getString('name'));
        if ('' !== $customName) {
            $connector->setName(mb_substr($customName, 0, 120));
        }
        $projectId = $payload->getInt('project');
        if ($projectId > 0) {
            $connector->setProject($this->entityManager->find(Project::class, $projectId));
        }
        $folderId = $payload->getInt('folder');
        if ($folderId > 0) {
            $connector->setFolder($this->entityManager->find(Folder::class, $folderId));
        }

        if (ConnectorPresets::FLOW_NOTION_TOKEN === $presetData['flow']) {
            $token = trim($payload->getString('token'));
            if ('' === $token) {
                $this->addFlash('error', new TranslatableMessage('flash.connector.notion_token_required'));

                return $this->redirectToRoute('connector_index');
            }
            $connector->setCredentials($this->credentials->seal(['Authorization' => 'Bearer '.$token], []));
            $this->entityManager->persist($connector);
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('flash.connector.guided_created', ['name' => $connector->getName()]));

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }

        if (ConnectorPresets::FLOW_MCP_OAUTH === $presetData['flow']) {
            $connector->setCredentials([]);
            $this->entityManager->persist($connector);
            $this->entityManager->flush();

            $state = bin2hex(random_bytes(16));
            try {
                $flow = $this->authorization->start($connector, $this->generateUrl('connector_authorize_check', [], UrlGeneratorInterface::ABSOLUTE_URL), $state);
            } catch (\Throwable $exception) {
                $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_impossible', ['error' => $exception->getMessage()]));

                return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
            }
            $request->getSession()->set(ConnectorOAuthState::MCP_AUTHORIZATION, [
                'state' => $state,
                'connector' => $connector->getId(),
                'flow' => $flow['pending'],
                'setup' => true,
            ]);

            return $this->redirect($flow['url']);
        }

        return $this->redirectToRoute('connector_new', ['preset' => $preset]);
    }
}
