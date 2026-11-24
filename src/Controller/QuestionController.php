<?php

namespace App\Controller;

use App\Entity\Project;
use App\Repository\RuleRepository;
use App\Rule\ShadowAreaDetector;
use App\Rule\ShadowCategory;
use App\Security\ProjectScope;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Business inbox listing every open shadow-area question across accessible projects (or one project),
 * so product/business stakeholders can answer ambiguities directly into the knowledge graph.
 */
final class QuestionController extends AbstractController
{
    public function __construct(
        private readonly RuleRepository $ruleRepository,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/questions', name: 'question_index', methods: ['GET'])]
    public function index(
        #[MapQueryParameter] ?string $project = null,
        #[MapQueryParameter] ?string $severity = null,
    ): Response {
        $projectEntity = $this->projectScope->bySlug($project);

        return $this->renderInbox($projectEntity, $severity, 'questions');
    }

    #[Route('/projects/{slug}/questions', name: 'project_questions', methods: ['GET'])]
    public function project(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        #[MapQueryParameter] ?string $severity = null,
    ): Response {
        return $this->renderInbox($project, $severity, 'project_questions');
    }

    private function renderInbox(?Project $project, ?string $severity, string $returnTo): Response
    {
        $severity = \in_array($severity, ShadowAreaDetector::SEVERITIES, true) ? $severity : null;
        $slugs = null === $project ? $this->projectScope->slugs() : null;

        return $this->render('question/index.html.twig', [
            'project' => $project,
            'projects' => $this->projectScope->projects(),
            'severity' => $severity,
            'severities' => ShadowAreaDetector::SEVERITIES,
            'categories' => array_column(ShadowCategory::cases(), null, 'value'),
            'questions' => $this->ruleRepository->openQuestions($project, $severity, $slugs),
            'return_to' => $returnTo,
        ]);
    }
}
