<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorMode;
use App\Review\PullRequestReviewer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/connectors/{id}/pull-requests', name: 'connector_pull_requests', requirements: ['id' => '\d+'], methods: ['GET'])]
final class ListPullRequestsAction extends AbstractController
{
    public function __construct(
        private readonly PullRequestReviewer $reviewer,
    ) {
    }

    public function __invoke(Connector $connector): Response
    {
        if (ConnectorMode::GitHub !== $connector->getMode()) {
            throw $this->createNotFoundException('Only a GitHub connector reviews pull requests.');
        }
        try {
            $pullRequests = $this->reviewer->openPullRequests($connector);
            $error = null;
        } catch (\Throwable $exception) {
            $pullRequests = [];
            $error = $exception->getMessage();
        }

        return $this->render('connector/pull_requests.html.twig', ['connector' => $connector, 'pull_requests' => $pullRequests, 'error' => $error]);
    }
}
