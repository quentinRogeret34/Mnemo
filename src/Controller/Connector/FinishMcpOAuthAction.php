<?php

namespace App\Controller\Connector;

use App\Connector\McpAuthorization;
use App\Entity\Connector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/connectors/authorize/check', name: 'connector_authorize_check', methods: ['GET'])]
final class FinishMcpOAuthAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly McpAuthorization $authorization,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $pending = $request->getSession()->remove(ConnectorOAuthState::MCP_AUTHORIZATION);
        $connector = \is_array($pending) && hash_equals((string) $pending['state'], $request->query->getString('state')) ? $this->entityManager->find(Connector::class, $pending['connector']) : null;
        if (null === $connector) {
            $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_expired'));

            return $this->redirectToRoute('connector_index');
        }

        if ($request->query->has('error')) {
            $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_denied', ['error' => $request->query->getString('error_description') ?: $request->query->getString('error')]));
        } else {
            try {
                $this->authorization->finish($connector, $pending['flow'], $request->query->getString('code'));
                $this->entityManager->flush();
                $this->addFlash('success', new TranslatableMessage('flash.connector.oauth_authorized'));
                if (true === ($pending['setup'] ?? false)) {
                    return $this->redirectToRoute('connector_setup', ['id' => $connector->getId()]);
                }
            } catch (\Throwable $exception) {
                $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_refused', ['error' => $exception->getMessage()]));
            }
        }

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }
}
