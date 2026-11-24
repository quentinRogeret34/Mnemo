<?php

namespace App\Controller\Project;

use App\Entity\Connector;
use App\Entity\Project;
use App\Form\ProjectType;
use App\Llm\LlmSettings;
use App\Llm\ModelCheck;
use App\Message\ReindexNodes;
use App\Project\ProjectGroupAssigner;
use App\Security\OAuth\OAuthClient;
use App\Security\ProjectScope;
use App\Security\SafeUrl;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/projects/{slug}/settings', name: 'project_settings', methods: ['GET', 'POST'])]
final class UpdateProjectSettingsAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectGroupAssigner $groupAssigner,
        private readonly ProjectScope $projectScope,
        private readonly OAuthClient $oauthClient,
        private readonly LlmSettings $llmSettings,
        private readonly ModelCheck $modelCheck,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
        private readonly SecretBox $secretBox,
    ) {
    }

    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $form = $this->createForm(ProjectType::class, $project, [
            'groups' => $this->isGranted('ROLE_SUPER_ADMIN'),
            'has_webhook' => $project->hasWebhook(),
        ]);
        if ($form->has('groups')) {
            $form->get('groups')->setData($this->groupAssigner->groupsOf($project));
        }

        $chatModel = $this->llmSettings->chatModel($project);
        $embeddingModel = $this->llmSettings->embeddingModel($project);
        $form->handleRequest($request);
        $configured = $this->llmSettings->isConfigured('openrouter');

        if ($form->isSubmitted() && $form->isValid() && $configured) {
            if ($chatModel !== $this->llmSettings->chatModel($project) && null !== $error = $this->modelCheck->chat($this->llmSettings->chatModel($project))) {
                $form->get('chatModel')->addError(new FormError($this->translator->trans('llm.refused_chat', ['error' => $error])));
            }
            if ($embeddingModel !== $this->llmSettings->embeddingModel($project) && null !== $error = $this->modelCheck->embedding($this->llmSettings->embeddingModel($project))) {
                $form->get('embeddingModel')->addError(new FormError($this->translator->trans('llm.refused_embedding', ['error' => $error])));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $rawWebhook = trim((string) $form->get('webhookUrl')->getData());
            if ($form->get('clearWebhook')->getData()) {
                $project->setWebhookUrlEncrypted(null);
            } elseif ('' !== $rawWebhook) {
                try {
                    $safe = SafeUrl::requireHttps($rawWebhook);
                    $project->setWebhookUrlEncrypted($this->secretBox->encrypt($safe));
                } catch (\RuntimeException $exception) {
                    $form->get('webhookUrl')->addError(new FormError($exception->getMessage()));
                }
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->has('groups')) {
                $this->groupAssigner->sync($project, $form->get('groups')->getData());
            }
            $this->entityManager->flush();
            $reindex = $configured && $embeddingModel !== $this->llmSettings->embeddingModel($project);
            if ($reindex) {
                $this->bus->dispatch(new ReindexNodes($project->getId()));
            }
            $this->addFlash('success', new TranslatableMessage($reindex ? 'flash.project.updated_reindex' : 'flash.project.updated'));

            return $this->redirectToRoute('project_settings', ['slug' => $project->getSlug()]);
        }
        if ($form->isSubmitted()) {
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
}
