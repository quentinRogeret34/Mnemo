<?php

namespace App\Controller;

use App\Entity\Connector;
use App\Entity\Project;
use App\Entity\UserGroup;
use App\Form\ProjectType;
use App\Llm\LlmSettings;
use App\Llm\ModelCheck;
use App\Message\ReindexNodes;
use App\Project\ProjectRemover;
use App\Project\ProjectResolver;
use App\Security\OAuth\OAuthClient;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectResolver $projectResolver,
        private readonly ProjectScope $projectScope,
        private readonly FormFactoryInterface $formFactory,
        private readonly OAuthClient $oauthClient,
        private readonly ProjectRemover $projectRemover,
        private readonly LlmSettings $llmSettings,
        private readonly ModelCheck $modelCheck,
        private readonly MessageBusInterface $bus,
    ) {
    }

    // The priority keeps "new" from being read as the slug of project_show.
    #[Route('/projects/new', name: 'project_new', methods: ['GET', 'POST'], priority: 1)]
    public function new(Request $request): Response
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
            $this->saveGroups($form, $project);
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('flash.project.created', ['name' => $project->getName()]));

            return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug()]);
        }

        return $this->render('project/new.html.twig', ['project_form' => $form->createView()], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/projects/{slug}/settings', name: 'project_settings', methods: ['GET', 'POST'])]
    public function settings(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $form = $this->createForm(ProjectType::class, $project, ['groups' => $this->isGranted('ROLE_SUPER_ADMIN')]);
        if ($form->has('groups')) {
            $form->get('groups')->setData(array_filter(
                $this->entityManager->getRepository(UserGroup::class)->findAll(),
                static fn (UserGroup $group): bool => $group->getProjects()->contains($project),
            ));
        }
        $chatModel = $this->llmSettings->chatModel($project);
        $embeddingModel = $this->llmSettings->embeddingModel($project);
        $form->handleRequest($request);
        $configured = $this->llmSettings->isConfigured('openrouter');
        if ($form->isSubmitted() && $form->isValid() && $configured) {
            if ($chatModel !== $this->llmSettings->chatModel($project) && null !== $error = $this->modelCheck->chat($this->llmSettings->chatModel($project))) {
                $form->get('chatModel')->addError(new FormError('OpenRouter a refusé ce modèle de chat : '.$error));
            }
            if ($embeddingModel !== $this->llmSettings->embeddingModel($project) && null !== $error = $this->modelCheck->embedding($this->llmSettings->embeddingModel($project))) {
                $form->get('embeddingModel')->addError(new FormError('OpenRouter a refusé ce modèle d\'embeddings : '.$error));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveGroups($form, $project);
            $this->entityManager->flush();
            $reindex = $configured && $embeddingModel !== $this->llmSettings->embeddingModel($project);
            if ($reindex) {
                $this->bus->dispatch(new ReindexNodes($project->getId()));
            }
            $this->addFlash('success', new TranslatableMessage($reindex ? 'flash.project.updated_reindex' : 'flash.project.updated'));

            return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug()]);
        }
        if ($form->isSubmitted()) {
            // The page around the form still names the project as it is stored.
            $this->entityManager->refresh($project);
        }

        return $this->render('project/settings.html.twig', [
            'project' => $project,
            'form' => $form,
            'llm' => $this->llmSettings,
            'connectors' => $this->entityManager->getRepository(Connector::class)->findBy(['project' => $project], ['name' => 'ASC']),
            'github_oauth' => isset($this->oauthClient->enabled()['github']),
            'scoped' => null !== $this->projectScope->slugs(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/projects/{slug}/archive', name: 'project_archive', methods: ['POST'])]
    public function archive(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $this->checkToken($project, $request);
        $project->archive();
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.project.archived', ['name' => $project->getName()]));

        return $this->redirectToRoute('project_index');
    }

    #[Route('/projects/{slug}/restore', name: 'project_restore', methods: ['POST'])]
    public function restore(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $this->checkToken($project, $request);
        $project->restore();
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.project.restored', ['name' => $project->getName()]));

        return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug()]);
    }

    #[Route('/projects/{slug}/delete', name: 'project_delete', methods: ['POST'])]
    public function delete(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $this->checkToken($project, $request);
        if ($project->getSlug() !== trim($request->getPayload()->getString('confirm'))) {
            $this->addFlash('error', new TranslatableMessage('flash.project.slug_mismatch'));

            return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug(), '_fragment' => 'danger']);
        }
        $name = $project->getName();
        $this->projectRemover->remove($project);
        $this->addFlash('success', new TranslatableMessage('flash.project.deleted', ['name' => $name]));

        return $this->redirectToRoute('project_index');
    }

    private function checkToken(Project $project, Request $request): void
    {
        if (!$this->isCsrfTokenValid('project-'.$project->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function saveGroups(FormInterface $form, Project $project): void
    {
        if (!$form->has('groups')) {
            return;
        }
        $chosen = [...$form->get('groups')->getData()];
        foreach ($this->entityManager->getRepository(UserGroup::class)->findAll() as $group) {
            $member = $group->getProjects()->contains($project);
            if (\in_array($group, $chosen, true) && !$member) {
                $group->getProjects()->add($project);
            } elseif (!\in_array($group, $chosen, true) && $member) {
                $group->getProjects()->removeElement($project);
            }
        }
    }
}
