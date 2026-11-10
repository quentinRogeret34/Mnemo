<?php

namespace App\Controller;

use App\Entity\Project;
use App\Graph\CapabilityDetector;
use App\Graph\GraphReader;
use App\Graph\KnowledgeReader;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class GraphController extends AbstractController
{
    public function __construct(
        private readonly GraphReader $graphReader,
        private readonly KnowledgeReader $knowledgeReader,
        private readonly CapabilityDetector $capabilityDetector,
    ) {
    }

    #[Route('/', name: 'project_index', methods: ['GET'])]
    public function listProjects(): Response
    {
        return $this->render('project/index.html.twig', [
            'projects' => $this->graphReader->projectSummaries(),
        ]);
    }

    #[Route('/projects/{slug}/code', name: 'project_code', methods: ['GET'])]
    public function showCode(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project): Response
    {
        return $this->render('project/code.html.twig', ['project' => $project, 'capabilities' => $this->capabilityDetector->detect($project)]);
    }

    #[Route('/projects/{slug}/files/{path}', name: 'file_show', requirements: ['path' => '.+'], methods: ['GET'])]
    public function showFile(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, string $path): Response
    {
        $file = $this->graphReader->findFile($project, $path) ?? throw $this->createNotFoundException(\sprintf('File "%s" is not in the graph.', $path));

        return $this->render('file/show.html.twig', [
            'project' => $project,
            'file' => $file,
            'symbols' => $this->graphReader->symbols($project, $path),
            'edges' => $this->graphReader->crossFileEdges($project, $path),
            'rules' => $this->knowledgeReader->rulesForFile($project, $path),
            'documents' => $this->knowledgeReader->documentsForFile($project, $path),
            'memories' => $this->knowledgeReader->memoriesAbout($file['id']),
        ]);
    }

    #[Route('/projects/{slug}/graph/{path}', name: 'file_graph', requirements: ['path' => '.+'], methods: ['GET'], format: 'json')]
    public function neighbourhood(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        string $path,
        #[MapQueryParameter(options: ['min_range' => 1, 'max_range' => 2])] int $depth = 1,
    ): JsonResponse {
        if (null === $this->graphReader->findFile($project, $path)) {
            throw $this->createNotFoundException(\sprintf('File "%s" is not in the graph.', $path));
        }

        return $this->json($this->graphReader->neighbourhood($project, $path, $depth));
    }
}
