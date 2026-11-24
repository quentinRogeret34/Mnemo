<?php

namespace App\MessageHandler;

use App\Entity\Project;
use App\Message\GardenProject;
use App\Message\RunAutoGardening;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues one bounded auto-gardening job per active project that opted into scheduled maintenance.
 */
#[AsMessageHandler]
final class RunAutoGardeningHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(RunAutoGardening $message): void
    {
        foreach ($this->entityManager->getRepository(Project::class)->findBy(['autoGarden' => true]) as $project) {
            if (!$project->isArchived() && null !== $project->getId()) {
                $this->bus->dispatch(new GardenProject($project->getId()));
            }
        }
    }
}
