<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/connectors/{id}/review', name: 'connector_review', requirements: ['id' => '\d+'], methods: ['POST'])]
final class TogglePullRequestReviewAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
        $enabled = true !== ($connector->getArguments()['review'] ?? false);
        $connector->setArguments(['review' => $enabled] + $connector->getArguments());
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage($enabled ? 'flash.connector.review_enabled' : 'flash.connector.review_disabled'));

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }
}
