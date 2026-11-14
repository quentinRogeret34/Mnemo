<?php

namespace App\Controller;

use App\Entity\Setting;
use App\Form\LlmSettingsType;
use App\Llm\LlmSettings;
use App\Llm\ModelCheck;
use App\Message\ReindexNodes;
use App\Security\ProjectScope;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Key and models of the whole instance, set from the interface instead of the environment.
 * Left to the super admin once the server is shared; on a local machine without login, to whoever uses it.
 */
final class LlmSettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LlmSettings $settings,
        private readonly ModelCheck $modelCheck,
        private readonly SecretBox $secretBox,
        private readonly ProjectScope $projectScope,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/settings/llm', name: 'llm_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $viewer = $this->projectScope->viewer();
        if (null !== $viewer && !$viewer->isSuperAdmin()) {
            throw $this->createAccessDeniedException('Reserved to the super admin.');
        }

        $form = $this->createForm(LlmSettingsType::class, [
            'chatModel' => $this->stored(LlmSettings::CHAT_MODEL)?->getValue(),
            'embeddingModel' => $this->stored(LlmSettings::EMBEDDING_MODEL)?->getValue(),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $key = trim((string) $form->get('apiKey')->getData());
            $remove = '' === $key && true === $form->get('removeKey')->getData();
            $chat = trim((string) $form->get('chatModel')->getData());
            $embedding = trim((string) $form->get('embeddingModel')->getData());
            $indexed = $this->settings->isConfigured('openrouter') ? $this->settings->embeddingModel() : null;

            // Without a key to call with, the models are saved as they are.
            if ('' !== $key || (!$remove && $this->settings->isConfigured('openrouter'))) {
                $tried = '' === $key ? null : $key;
                if (null !== $error = $this->modelCheck->chat('' === $chat ? $this->settings->defaultChatModel : $chat, $tried)) {
                    $form->get('' === $key ? 'chatModel' : 'apiKey')->addError(new FormError('OpenRouter a refusé le modèle de chat avec cette clé : '.$error));
                } elseif (null !== $error = $this->modelCheck->embedding('' === $embedding ? $this->settings->defaultEmbeddingModel : $embedding, $tried)) {
                    $form->get('embeddingModel')->addError(new FormError('OpenRouter a refusé le modèle d\'embeddings : '.$error));
                }
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->store(LlmSettings::CHAT_MODEL, '' === $chat ? null : $chat);
            $this->store(LlmSettings::EMBEDDING_MODEL, '' === $embedding ? null : $embedding);
            if ('' !== $key) {
                $this->store(LlmSettings::KEY, $this->secretBox->encrypt($key));
            } elseif ($remove) {
                $this->store(LlmSettings::KEY, null);
            }
            $this->entityManager->flush();
            $this->settings->reset();

            $reindex = $this->settings->isConfigured('openrouter') && $indexed !== $this->settings->embeddingModel();
            if ($reindex) {
                $this->bus->dispatch(new ReindexNodes());
            }
            $this->addFlash('success', $reindex ? 'Réglages enregistrés. Le contenu est réindexé en arrière-plan avec le modèle d\'embeddings.' : 'Réglages des modèles enregistrés.');

            return $this->redirectToRoute('llm_settings');
        }

        return $this->render('settings/llm.html.twig', ['form' => $form, 'llm' => $this->settings], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function stored(string $name): ?Setting
    {
        return $this->entityManager->find(Setting::class, $name);
    }

    private function store(string $name, ?string $value): void
    {
        $setting = $this->stored($name);
        if (null === $value) {
            if (null !== $setting) {
                $this->entityManager->remove($setting);
            }
        } elseif (null === $setting) {
            $this->entityManager->persist(new Setting($name, $value));
        } else {
            $setting->setValue($value);
        }
    }
}
