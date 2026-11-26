<?php

namespace App\Controller\Factory;

use App\Enum\AgentRunnerKind;
use App\Enum\SwarmOrderKind;
use App\Factory\FactoryGuard;
use App\Factory\Runner\AgentRunnerRegistry;
use App\Factory\Swarm\SwarmBriefGenerator;
use App\Factory\Swarm\SwarmHubManager;
use App\Factory\WorktreeManager;
use App\Repository\AgentSessionRepository;
use App\Repository\SwarmNodeRepository;
use App\Repository\SwarmOrderRepository;
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
        private readonly SwarmNodeRepository $nodeRepository,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly SwarmHubManager $hubManager,
        private readonly SwarmBriefGenerator $briefGenerator,
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
        $nodes = $this->nodeRepository->forSwarm($swarm);
        $orders = $this->orderRepository->forSwarm($swarm);
        $hubToken = $this->hubManager->resolveHubToken($swarm);

        $urlJoinPrompt = null;
        $orderUrls = [];
        if (null !== $hubToken) {
            $urlJoinPrompt = $this->briefGenerator->urlJoinPrompt($swarm, $hubToken);
            foreach ($orders as $order) {
                $orderUrls[$order->getPublicId()] = $this->briefGenerator->orderDirectUrl($swarm, $order, $hubToken);
            }
        }

        $runnerAvailable = AgentRunnerKind::ExternalHub === $swarm->getWorkerRunner()
            || ($this->runnerRegistry->availability()[$swarm->getWorkerRunner()->value] ?? false);

        return $this->render('factory/swarm_show.html.twig', [
            'project' => $swarm->getProject(),
            'swarm' => $swarm,
            'workers' => $workers,
            'messages' => $this->swarmRepository->messagesFor($swarm),
            'nodes' => $nodes,
            'orders' => $orders,
            'order_kinds' => SwarmOrderKind::cases(),
            'hub_token' => $hubToken,
            'mcp_join_prompt' => $this->briefGenerator->mcpJoinPrompt($swarm),
            'url_join_prompt' => $urlJoinPrompt,
            'order_urls' => $orderUrls,
            'runner_available' => $runnerAvailable,
            'is_git_repo' => $this->worktreeManager->isGitRepository($swarm->getProject()),
        ]);
    }
}
