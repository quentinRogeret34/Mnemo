<?php

namespace App\MessageHandler;

use App\Entity\GroomingRun;
use App\Grooming\GroomingSwarmRunner;
use App\Message\RunGroomingSwarm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunGroomingSwarmHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GroomingSwarmRunner $runner,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function __invoke(RunGroomingSwarm $message): void
    {
        $run = $this->entityManager->find(GroomingRun::class, $message->runId);
        if (null === $run || !$run->isRunning()) {
            return;
        }

        $lock = $this->lockFactory->createLock('grooming-run-'.$message->runId, 300);
        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->runner->execute($run);
        } finally {
            $lock->release();
        }
    }
}
