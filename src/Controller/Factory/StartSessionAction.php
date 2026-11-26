<?php

namespace App\Controller\Factory;

use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Factory\AgentSessionRecorder;
use App\Factory\FactoryGuard;
use App\Security\ProjectScope;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class StartSessionAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly AgentSessionRecorder $recorder,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/sessions', name: 'factory_workspace_session_start', methods: ['POST'])]
    #[Route('/projects/{slug}/factory/sessions', name: 'factory_session_start', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['slug' => 'slug'])]
        ?Project $project = null,
    ): RedirectResponse {
        $this->factoryGuard->requireAccess();
        $csrfId = null === $project ? 'factory_session_start_workspace' : 'factory_session_start_'.$project->getSlug();
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
        if ('' === $title) {
            $this->addFlash('error', 'factory.flash.title_required');

            return $isWorkspace
                ? $this->redirectToRoute('factory_index', ['project' => $project->getSlug()])
                : $this->redirectToRoute('project_factory', ['slug' => $project->getSlug()]);
        }

        $runner = AgentRunnerKind::tryFrom($request->request->getString('runner')) ?? AgentRunnerKind::ExternalMcp;
        $role = trim($request->request->getString('role')) ?: 'developer';
        $goal = trim($request->request->getString('goal')) ?: null;
        $branch = trim($request->request->getString('branch')) ?: null;
        $ruleCode = trim($request->request->getString('rule_code')) ?: null;
        $client = trim($request->request->getString('client')) ?: null;

        $session = $this->recorder->start(
            project: $project,
            title: $title,
            runner: $runner,
            role: $role,
            goal: $goal,
            client: $client,
            branch: $branch,
            sourceRuleCode: $ruleCode,
            startedBy: $this->getUser()?->getUserIdentifier() ?? 'local',
        );

        $this->addFlash('notice', 'factory.flash.started');

        return $this->redirectToRoute('factory_session_show', ['publicId' => $session->getPublicId()]);
    }
}
