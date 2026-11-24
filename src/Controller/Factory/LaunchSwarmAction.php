<?php

namespace App\Controller\Factory;

use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Enum\SwarmStrategy;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmOrchestrator;
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
    ) {
    }

    #[Route('/projects/{slug}/factory/swarms', name: 'factory_swarm_launch', methods: ['POST'])]
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Project $project,
        Request $request,
    ): RedirectResponse {
        $this->factoryGuard->requireAccess();
        if (!$this->isCsrfTokenValid('factory_swarm_launch_'.$project->getSlug(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $title = trim($request->request->getString('title'));
        $goal = trim($request->request->getString('goal'));
        if ('' === $title || '' === $goal) {
            $this->addFlash('error', 'factory.swarm.flash.title_goal_required');

            return $this->redirectToRoute('project_factory', ['slug' => $project->getSlug()]);
        }

        $strategy = SwarmStrategy::tryFrom($request->request->getString('strategy')) ?? SwarmStrategy::Parallel;
        $runner = AgentRunnerKind::tryFrom($request->request->getString('worker_runner')) ?? AgentRunnerKind::InternalLlm;
        $requireApproval = '1' === $request->request->getString('require_plan_approval');
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
        );

        if ($runSync) {
            $this->orchestrator->advanceSynchronously($swarm);
        }

        $this->addFlash('notice', 'factory.swarm.flash.launched');

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }
}
