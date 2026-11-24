<?php

namespace App\Controller;

use App\Alignment\AlignmentReport;
use App\Entity\Project;
use App\Security\ProjectScope;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class AlignmentController extends AbstractController
{
    public function __construct(
        private readonly AlignmentReport $alignmentReport,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/alignment', name: 'alignment_index', methods: ['GET'])]
    public function index(
        #[MapQueryParameter] ?string $project = null,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^(drift|to_clarify|unlinked|aligned)$/'])] ?string $filter = null,
    ): Response {
        $projectEntity = $this->projectScope->bySlug($project);
        $report = $this->alignmentReport->build($projectEntity, $filter);

        return $this->render('alignment/show.html.twig', [
            'project' => $projectEntity,
            'projects' => $this->projectScope->projects(),
            'project_mode' => false,
            'filter' => $filter,
            'filters' => AlignmentReport::FILTERS,
            'summary' => $report->summary,
            'rows' => $report->rows,
        ]);
    }

    #[Route('/projects/{slug}/alignment', name: 'project_alignment', methods: ['GET'])]
    public function project(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^(drift|to_clarify|unlinked|aligned)$/'])] ?string $filter = null,
    ): Response {
        $report = $this->alignmentReport->build($project, $filter);

        return $this->render('alignment/show.html.twig', [
            'project' => $project,
            'projects' => [],
            'project_mode' => true,
            'filter' => $filter,
            'filters' => AlignmentReport::FILTERS,
            'summary' => $report->summary,
            'rows' => $report->rows,
        ]);
    }
}
