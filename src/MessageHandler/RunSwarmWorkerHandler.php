<?php

namespace App\MessageHandler;

use App\Entity\AgentSession;
use App\Entity\SwarmRun;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Message\RunSwarmWorker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunSwarmWorkerHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SwarmOrchestrator $orchestrator,
    ) {
    }

    public function __invoke(RunSwarmWorker $message): void
    {
        $swarm = $this->entityManager->find(SwarmRun::class, $message->swarmId);
        $session = $this->entityManager->find(AgentSession::class, $message->sessionId);
        if (null === $swarm || null === $session) {
            return;
        }

        $this->orchestrator->executeWorker($swarm, $session, $message->taskIndex);
    }
}
