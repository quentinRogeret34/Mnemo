<?php

namespace App\Controller\Project;

use App\Form\ProjectType;
use App\Project\ProjectGroupAssigner;
use App\Project\ProjectResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

// The priority keeps "new" from being read as the slug of project_show.
#[Route('/projects/new', name: 'project_new', methods: ['GET', 'POST'], priority: 1)]
final class CreateProjectAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectResolver $projectResolver,
        private readonly ProjectGroupAssigner $groupAssigner,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->projectResolver->mayCreate()) {
            throw new AccessDeniedHttpException('This account may not create a project.');
        }

        $form = $this->formFactory->createNamed('new_project', ProjectType::class, null, [
            'create' => true,
            'groups' => $this->isGranted('ROLE_SUPER_ADMIN'),
            'action' => $this->generateUrl('project_new'),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $project = $form->getData();
            $this->projectResolver->register($project);
            if ($form->has('groups')) {
                $this->groupAssigner->sync($project, $form->get('groups')->getData());
            }
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('flash.project.created', ['name' => $project->getName()]));

            return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug()]);
        }

        return $this->render('project/new.html.twig', ['project_form' => $form->createView()], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
