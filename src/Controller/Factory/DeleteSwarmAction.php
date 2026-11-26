<?php

namespace App\Controller\Factory;

use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DeleteSwarmAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrchestrator $orchestrator,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/delete', name: 'factory_swarm_delete', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();
        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }
        if (!$this->isCsrfTokenValid('factory_swarm_delete_'.$swarm->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $projectSlug = $swarm->getProject()->getSlug();
        $wasArchived = $swarm->isArchived();
        $this->orchestrator->delete($swarm);
        $this->addFlash('notice', 'factory.swarm.flash.deleted');

        $returnScope = $request->request->getString('return_scope', 'project');
        if ('workspace' === $returnScope) {
            return $this->redirectToRoute('factory_index', $wasArchived ? ['archived' => 1] : []);
        }

        return $this->redirectToRoute('project_factory', array_filter([
            'slug' => $projectSlug,
            'archived' => $wasArchived ? 1 : null,
        ], static fn (mixed $v): bool => null !== $v));
    }
}
