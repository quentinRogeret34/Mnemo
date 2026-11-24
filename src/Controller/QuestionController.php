<?php

namespace App\Controller;

use App\Entity\Project;
use App\Rule\ShadowAreaDetector;
use App\Rule\ShadowCategory;
use App\Security\ProjectScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
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
        private readonly Connection $connection,
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

        $items = [];
        if ([] !== $slugs) {
            $scopeSql = null === $slugs ? '' : ' AND p.slug IN (:slugs)';
            $severitySql = null === $severity ? '' : " AND sa.elem->>'severity' = :severity";
            $params = ['project' => $project?->getId()]
                + (null === $slugs ? [] : ['slugs' => $slugs])
                + (null === $severity ? [] : ['severity' => $severity]);
            $types = null === $slugs ? [] : ['slugs' => ArrayParameterType::STRING];

            $rows = $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT r.id AS rule_id, r.external_id AS rule_code, r.label AS rule_title, r.metadata->>'statement' AS rule_statement,
                           p.slug AS project_slug, p.name AS project_name,
                           (sa.ord - 1) AS area_index,
                           sa.elem->>'severity' AS severity,
                           sa.elem->>'category' AS category,
                           sa.elem->>'gap' AS gap,
                           sa.elem->>'question' AS question
                    FROM node r
                    JOIN project p ON p.id = r.project_id
                    CROSS JOIN LATERAL jsonb_array_elements(r.metadata->'shadow_areas') WITH ORDINALITY AS sa(elem, ord)
                    WHERE r.type = 'business_rule'
                      AND jsonb_typeof(r.metadata->'shadow_areas') = 'array'
                      AND (CAST(:project AS int) IS NULL OR r.project_id = :project){$scopeSql}{$severitySql}
                    ORDER BY CASE sa.elem->>'severity' WHEN 'blocker' THEN 0 WHEN 'major' THEN 1 ELSE 2 END, p.name, r.external_id, sa.ord
                    SQL,
                $params,
                $types,
            );
            $items = array_map(
                static fn (array $row): array => ['rule_id' => (int) $row['rule_id'], 'area_index' => (int) $row['area_index']] + $row,
                $rows,
            );
        }

        return $this->render('question/index.html.twig', [
            'project' => $project,
            'projects' => $this->projectScope->projects(),
            'severity' => $severity,
            'severities' => ShadowAreaDetector::SEVERITIES,
            'categories' => array_column(ShadowCategory::cases(), null, 'value'),
            'questions' => $items,
            'return_to' => $returnTo,
        ]);
    }
}
