<?php

namespace App\Controller\Factory;

use App\Entity\SwarmRun;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ManageSwarmHubTokenAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmHubManager $hubManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/hub-token', name: 'factory_swarm_hub_token', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();

        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }

        if (!$this->isCsrfTokenValid('factory_swarm_hub_token_'.$swarm->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = $request->request->getString('action');

        match ($action) {
            'enable' => $this->handleEnable($swarm),
            'rotate' => $this->handleRotate($swarm),
            'revoke' => $this->handleRevoke($swarm),
            default => null,
        };

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }

    private function handleEnable(SwarmRun $swarm): void
    {
        $this->hubManager->enableHubToken($swarm);
        $this->addFlash('notice', 'factory.hub.flash.token_enabled');
    }

    private function handleRotate(SwarmRun $swarm): void
    {
        $this->hubManager->rotateHubToken($swarm);
        $this->addFlash('notice', 'factory.hub.flash.token_rotated');
    }

    private function handleRevoke(SwarmRun $swarm): void
    {
        $this->hubManager->revokeHubToken($swarm);
        $this->addFlash('notice', 'factory.hub.flash.token_revoked');
    }
}
