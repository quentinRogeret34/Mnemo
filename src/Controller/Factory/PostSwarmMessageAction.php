<?php

namespace App\Controller\Factory;

use App\Enum\SwarmMessageKind;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PostSwarmMessageAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrchestrator $orchestrator,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/messages', name: 'factory_swarm_message', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();
        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }
        if (!$this->isCsrfTokenValid('factory_swarm_message_'.$swarm->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $body = trim($request->request->getString('body'));
        if ('' === $body) {
            $this->addFlash('error', 'factory.flash.message_required');

            return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
        }

        $this->orchestrator->postMessage(
            $swarm,
            $this->getUser()?->getUserIdentifier() ?? 'human',
            SwarmMessageKind::Human,
            $body,
        );
        $this->addFlash('notice', 'factory.swarm.flash.message_posted');

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }
}
