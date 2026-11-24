<?php

namespace App\Controller\Connector;

use App\Connector\McpAuthorization;
use App\Entity\Connector;
use App\Enum\ConnectorTransport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/connectors/{id}/authorize', name: 'connector_authorize', requirements: ['id' => '\d+'], methods: ['POST'])]
final class StartMcpOAuthAction extends AbstractController
{
    public function __construct(
        private readonly McpAuthorization $authorization,
    ) {
    }

    public function __invoke(Connector $connector, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        if (ConnectorTransport::Http !== $connector->getTransport() || $connector->getMode()->isApi()) {
            throw $this->createNotFoundException('Only an HTTP MCP connector is authorized by OAuth.');
        }
        $state = bin2hex(random_bytes(16));
        try {
            $flow = $this->authorization->start($connector, $this->generateUrl('connector_authorize_check', [], UrlGeneratorInterface::ABSOLUTE_URL), $state);
        } catch (\Throwable $exception) {
            $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_impossible', ['error' => $exception->getMessage()]));

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }
        $request->getSession()->set(ConnectorOAuthState::MCP_AUTHORIZATION, ['state' => $state, 'connector' => $connector->getId(), 'flow' => $flow['pending']]);

        return $this->redirect($flow['url']);
    }
}
