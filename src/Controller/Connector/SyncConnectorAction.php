<?php

namespace App\Controller\Connector;

use App\Connector\ConnectorRunner;
use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Enum\ConnectorMode;
use App\Message\SyncConnector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/connectors/{id}/sync', name: 'connector_sync', requirements: ['id' => '\d+'], methods: ['POST'])]
final class SyncConnectorAction extends AbstractController
{
    public function __construct(
        private readonly ConnectorRunner $runner,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(Connector $connector, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        if (ConnectorMode::Notion === $connector->getMode() || ($connector->getMode()->isForge() && true === ($connector->getArguments()['code_graph'] ?? false))) {
            $this->bus->dispatch(new SyncConnector($connector->getId(), ConnectorRun::MANUAL));
            $this->addFlash('success', new TranslatableMessage('flash.connector.sync_started'));

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }
        $report = $this->runner->sync($connector);
        if (null === $report['error']) {
            $this->addFlash('success', new TranslatableMessage('flash.connector.imported', ['created' => $report['created'], 'updated' => $report['updated'], 'unchanged' => $report['unchanged'], 'skipped' => \count($report['skipped'])]));
        }

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }
}
