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

final class ArchiveSwarmAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrchestrator $orchestrator,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/archive', name: 'factory_swarm_archive', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();
        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }
        if (!$this->isCsrfTokenValid('factory_swarm_archive_'.$swarm->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = $request->request->getString('action', 'archive');
        $actor = $this->getUser()?->getUserIdentifier() ?? 'human';

        if ('unarchive' === $action) {
            $this->orchestrator->unarchive($swarm);
            $this->addFlash('notice', 'factory.swarm.flash.unarchived');
        } else {
            $this->orchestrator->archive($swarm, $actor);
            $this->addFlash('notice', 'factory.swarm.flash.archived');
        }

        $returnScope = $request->request->getString('return_scope', 'project');
        if ('workspace' === $returnScope) {
            return $this->redirectToRoute('factory_index', 'unarchive' === $action ? ['archived' => 1] : []);
        }

        return $this->redirectToRoute('project_factory', array_filter([
            'slug' => $swarm->getProject()->getSlug(),
            'archived' => 'unarchive' === $action ? 1 : null,
        ], static fn (mixed $v): bool => null !== $v));
    }
}
