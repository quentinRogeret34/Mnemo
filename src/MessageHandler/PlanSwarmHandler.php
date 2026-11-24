<?php

namespace App\MessageHandler;

use App\Entity\SwarmRun;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Message\PlanSwarm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PlanSwarmHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SwarmOrchestrator $orchestrator,
    ) {
    }

    public function __invoke(PlanSwarm $message): void
    {
        $swarm = $this->entityManager->find(SwarmRun::class, $message->swarmId);
        if (null === $swarm) {
            return;
        }

        $this->orchestrator->plan($swarm);
    }
}
