<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorMode;
use App\Llm\LlmSettings;
use App\Llm\ModelCheck;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/connectors/{id}/review-model', name: 'connector_review_model', requirements: ['id' => '\d+'], methods: ['POST'])]
final class UpdateReviewModelAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LlmSettings $llmSettings,
        private readonly ModelCheck $modelCheck,
    ) {
    }

    public function __invoke(Connector $connector, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        if (ConnectorMode::GitHub !== $connector->getMode()) {
            throw $this->createNotFoundException('Only a GitHub connector reviews pull requests.');
        }
        $model = trim($request->getPayload()->getString('model'));
        $arguments = $connector->getArguments();
        unset($arguments['review_model']);
        if ('' === $model) {
            $this->addFlash('success', new TranslatableMessage('flash.connector.review_model_default', ['model' => $this->llmSettings->chatModel($connector->getProject())]));
        } elseif (mb_strlen($model) > 150 || null !== $error = $this->modelCheck->chat($model)) {
            $this->addFlash('error', new TranslatableMessage('flash.connector.review_model_refused', ['model' => mb_substr($model, 0, 150), 'error' => $error ?? new TranslatableMessage('flash.connector.model_too_long')]));

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        } else {
            $arguments['review_model'] = $model;
            $this->addFlash('success', new TranslatableMessage('flash.connector.review_model', ['model' => $model]));
        }
        $connector->setArguments($arguments);
        $this->entityManager->flush();

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }
}
