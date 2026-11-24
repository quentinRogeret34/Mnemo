<?php

namespace App\Controller\Studio;

use App\Entity\Project;
use App\Studio\ProjectStudioLoader;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StudioShowAction extends AbstractController
{
    public function __construct(
        private readonly ProjectStudioLoader $studioLoader,
    ) {
    }

    #[Route('/projects/{slug}/studio', name: 'project_studio', methods: ['GET'])]
    public function __invoke(
        #[MapEntity(mapping: ['slug' => 'slug'])]
        Project $project,
        Request $request,
    ): Response {
        $loaded = $this->studioLoader->load($project);
        $requestedRef = $request->query->getString('file');
        $active = '' !== $requestedRef && isset($loaded['all'][$requestedRef])
            ? $loaded['all'][$requestedRef]
            : $loaded['definition'];

        return $this->render('studio/index.html.twig', [
            'project' => $project,
            'definition' => $loaded['definition'],
            'groups' => $loaded['groups'],
            'active' => $active,
        ]);
    }
}
