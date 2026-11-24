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

final class SwarmControlAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrchestrator $orchestrator,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/control', name: 'factory_swarm_control', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();
        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }
        if (!$this->isCsrfTokenValid('factory_swarm_control_'.$swarm->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = $request->request->getString('action');
        $actor = $this->getUser()?->getUserIdentifier() ?? 'human';

        match ($action) {
            'pause' => $this->orchestrator->pause($swarm, $actor),
            'resume' => $this->orchestrator->resume($swarm, $actor),
            'cancel' => $this->orchestrator->cancel($swarm, $actor),
            'run_now' => $this->orchestrator->advanceSynchronously($swarm),
            default => null,
        };

        $this->addFlash('notice', 'factory.swarm.flash.updated');

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }
}
