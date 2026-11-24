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

final class ApproveSwarmAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrchestrator $orchestrator,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/approve', name: 'factory_swarm_approve', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();
        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }
        if (!$this->isCsrfTokenValid('factory_swarm_approve_'.$swarm->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->orchestrator->approveAndDispatch($swarm);
        if ('1' === $request->request->getString('run_sync')) {
            $this->orchestrator->advanceSynchronously($swarm);
        }

        $this->addFlash('notice', 'factory.swarm.flash.approved');

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }
}
