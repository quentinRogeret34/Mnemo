<?php

namespace App\MessageHandler;

use App\Entity\Connector;
use App\Message\SyncConnector;
use App\Message\SyncDueConnectors;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class SyncDueConnectorsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SyncDueConnectors $message): void
    {
        $now = new \DateTimeImmutable();
        foreach ($this->entityManager->getRepository(Connector::class)->findAll() as $connector) {
            if ($connector->getSchedule()->isDue($connector->getLastRunAt(), $now)) {
                $this->bus->dispatch(new SyncConnector($connector->getId()));
            }
        }
    }
}
