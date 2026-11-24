<?php

namespace App\Controller\Factory;

use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Enum\AgentSessionStatus;
use App\Factory\FactoryGuard;
use App\Factory\HookConfigGenerator;
use App\Repository\AgentSessionRepository;
use App\Security\ProjectScope;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FactoryIndexAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly HookConfigGenerator $hookConfigGenerator,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory', name: 'factory_index', methods: ['GET'])]
    #[Route('/projects/{slug}/factory', name: 'project_factory', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['slug' => 'slug'])]
        ?Project $project = null,
    ): Response {
        $this->factoryGuard->requireAccess();

        $statusParam = $request->query->getString('status');
        $statusFilter = '' !== $statusParam ? AgentSessionStatus::tryFrom($statusParam) : null;
        $allowed = null === $this->projectScope->slugs() ? null : $this->projectScope->projects();

        $sessions = $this->sessionRepository->recent($project, $statusFilter, 60, $allowed);
        $stats = $this->sessionRepository->summaryStats($project, $allowed);

        return $this->render('factory/index.html.twig', [
            'project' => $project,
            'projects' => $this->projectScope->projects(),
            'sessions' => $sessions,
            'stats' => $stats,
            'status_filter' => $statusFilter,
            'statuses' => AgentSessionStatus::cases(),
            'runners' => AgentRunnerKind::cases(),
            'hook_snippet' => $this->hookConfigGenerator->claudeCodeHooksJson($project),
            'hook_url' => $this->hookConfigGenerator->hookEndpointUrl(),
        ]);
    }
}
