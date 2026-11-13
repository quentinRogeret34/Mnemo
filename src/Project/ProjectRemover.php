<?php

namespace App\Project;

use App\Entity\Connector;
use App\Entity\Project;
use App\Forge\RepositoryCheckout;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a project with what only exists for it: its code graph, its rules, and its connectors with their clones.
 * The database only detaches a connector from a deleted project, and a forge connector without project fails at
 * every scheduled synchronisation.
 */
final class ProjectRemover
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryCheckout $checkout,
    ) {
    }

    public function remove(Project $project): void
    {
        foreach ($this->entityManager->getRepository(Connector::class)->findBy(['project' => $project]) as $connector) {
            $this->checkout->remove($connector);
            $this->entityManager->remove($connector);
        }
        $this->entityManager->remove($project);
        $this->entityManager->flush();
    }
}
