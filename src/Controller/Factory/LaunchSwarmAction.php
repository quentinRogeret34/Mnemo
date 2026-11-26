<?php

namespace App\Controller\Factory;

use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Enum\SwarmStrategy;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Security\ProjectScope;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LaunchSwarmAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmOrchestrator $orchestrator,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms', name: 'factory_workspace_swarm_launch', methods: ['POST'])]
    #[Route('/projects/{slug}/factory/swarms', name: 'factory_swarm_launch', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['slug' => 'slug'])]
        ?Project $project = null,
    ): RedirectResponse {
        $this->factoryGuard->requireAccess();
        $csrfId = null === $project ? 'factory_swarm_launch_workspace' : 'factory_swarm_launch_'.$project->getSlug();
        if (!$this->isCsrfTokenValid($csrfId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $isWorkspace = null === $project;
        if (null === $project) {
            $project = $this->projectScope->bySlug(trim($request->request->getString('project')));
            if (null === $project) {
                $this->addFlash('error', 'factory.flash.project_required');

                return $this->redirectToRoute('factory_index');
            }
        }

        $title = trim($request->request->getString('title'));
        $goal = trim($request->request->getString('goal'));
        if ('' === $title || '' === $goal) {
            $this->addFlash('error', 'factory.swarm.flash.title_goal_required');

            return $isWorkspace
                ? $this->redirectToRoute('factory_index', ['project' => $project->getSlug()])
                : $this->redirectToRoute('project_factory', ['slug' => $project->getSlug()]);
        }

        $strategy = SwarmStrategy::tryFrom($request->request->getString('strategy')) ?? SwarmStrategy::Parallel;
        $runner = AgentRunnerKind::tryFrom($request->request->getString('worker_runner')) ?? AgentRunnerKind::InternalLlm;
        $requireApproval = '1' === $request->request->getString('require_plan_approval');
        $skipInitialPlan = '1' === $request->request->getString('skip_initial_plan');
        $ruleCode = trim($request->request->getString('rule_code')) ?: null;
        $baseBranch = trim($request->request->getString('base_branch')) ?: null;
        $runSync = '1' === $request->request->getString('run_sync');

        $swarm = $this->orchestrator->launch(
            project: $project,
            title: $title,
            goal: $goal,
            strategy: $strategy,
            workerRunner: $runner,
            requirePlanApproval: $requireApproval,
            sourceRuleCode: $ruleCode,
            baseBranch: $baseBranch,
            startedBy: $this->getUser()?->getUserIdentifier() ?? 'local',
            skipInitialPlan: $skipInitialPlan,
        );

        if ($runSync && !$skipInitialPlan) {
            $this->orchestrator->advanceSynchronously($swarm);
        }

        $this->addFlash('notice', $skipInitialPlan ? 'factory.hub.flash.launched' : 'factory.swarm.flash.launched');

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }
}
