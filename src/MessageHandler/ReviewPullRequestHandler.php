<?php

namespace App\MessageHandler;

use App\Entity\Connector;
use App\Message\ReviewPullRequest;
use App\Review\PullRequestReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReviewPullRequestHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PullRequestReviewer $reviewer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReviewPullRequest $message): void
    {
        $connector = $this->entityManager->find(Connector::class, $message->connectorId);
        if (null === $connector) {
            return;
        }

        // Not retried: the next push to the pull request asks for a fresh review anyway.
        try {
            $this->reviewer->review($connector, $message->number);
        } catch (\Throwable $exception) {
            $this->logger->error('Review of pull request #{number} of {repository} failed: {error}', [
                'number' => $message->number,
                'repository' => $connector->getArguments()['repository'] ?? '',
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
