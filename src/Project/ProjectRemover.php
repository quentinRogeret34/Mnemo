<?php

namespace App\Project;

use App\Entity\Connector;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Forge\RepositoryCheckout;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a project with what only exists for it: its code graph, its rules, its proposals, and its connectors with their clones.
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
        $connectors = $this->entityManager->getRepository(Connector::class)->findBy(['project' => $project]);
        $connectorIds = array_values(array_filter(array_map(static fn (Connector $c): ?int => $c->getId(), $connectors)));
        foreach ($connectors as $connector) {
            $this->entityManager->remove($connector);
        }
        foreach ($this->entityManager->getRepository(Proposal::class)->findBy(['project' => $project]) as $proposal) {
            $this->entityManager->remove($proposal);
        }
        $this->entityManager->remove($project);
        $this->entityManager->flush();

        foreach ($connectorIds as $connectorId) {
            $this->checkout->removeById($connectorId);
        }
    }
}
