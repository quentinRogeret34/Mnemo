<?php

namespace App\Controller\Factory;

use App\Factory\FactoryGuard;
use App\Factory\Runner\AgentRunnerRegistry;
use App\Factory\WorktreeManager;
use App\Repository\AgentSessionRepository;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowSwarmAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly AgentRunnerRegistry $runnerRegistry,
        private readonly WorktreeManager $worktreeManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}', name: 'factory_swarm_show', methods: ['GET'])]
    public function __invoke(string $publicId): Response
    {
        $this->factoryGuard->requireAccess();

        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }

        $coordinator = $swarm->getCoordinatorSession();
        $workers = null !== $coordinator ? $this->sessionRepository->childrenOf($coordinator) : [];

        return $this->render('factory/swarm_show.html.twig', [
            'project' => $swarm->getProject(),
            'swarm' => $swarm,
            'workers' => $workers,
            'messages' => $this->swarmRepository->messagesFor($swarm),
            'runner_available' => $this->runnerRegistry->availability()[$swarm->getWorkerRunner()->value] ?? false,
            'is_git_repo' => $this->worktreeManager->isGitRepository($swarm->getProject()),
        ]);
    }
}
