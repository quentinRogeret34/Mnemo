<?php

namespace App\MessageHandler;

use App\Connector\ConnectorRunner;
use App\Entity\Connector;
use App\Message\SyncConnector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncConnectorHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorRunner $runner,
    ) {
    }

    public function __invoke(SyncConnector $message): void
    {
        $connector = $this->entityManager->find(Connector::class, $message->connectorId);
        if (null !== $connector) {
            $this->runner->sync($connector, $message->trigger);
        }
    }
}
