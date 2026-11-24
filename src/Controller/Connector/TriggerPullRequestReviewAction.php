<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorMode;
use App\Message\ReviewPullRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/connectors/{id}/pull-requests/{number}/review', name: 'connector_pull_request_review', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['POST'])]
final class TriggerPullRequestReviewAction extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(Connector $connector, int $number, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        if (ConnectorMode::GitHub !== $connector->getMode()) {
            throw $this->createNotFoundException('Only a GitHub connector reviews pull requests.');
        }
        $this->bus->dispatch(new ReviewPullRequest($connector->getId(), $number));
        $this->addFlash('success', new TranslatableMessage('flash.connector.review_started', ['number' => $number]));

        return $this->redirectToRoute('connector_pull_requests', ['id' => $connector->getId()]);
    }
}
