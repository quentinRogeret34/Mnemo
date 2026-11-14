<?php

namespace App\Controller;

use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Enum\ConnectorMode;
use App\Forge\GitHubApp;
use App\Message\ReviewPullRequest;
use App\Message\SyncConnector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives the events of the GitHub App: a push on the default branch synchronises the connectors of the repository,
 * a pull request that opens or changes is reviewed by those that enabled the review.
 */
final class GitHubWebhookController extends AbstractController
{
    private const REVIEWED_ACTIONS = ['opened', 'reopened', 'synchronize', 'ready_for_review', 'edited'];

    public function __construct(
        private readonly GitHubApp $app,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/webhooks/github', name: 'github_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->app->isConfigured()) {
            throw $this->createNotFoundException('The GitHub App is not configured.');
        }
        if (!$this->app->isSignedBy($request->getContent(), (string) $request->headers->get('X-Hub-Signature-256'))) {
            return $this->json(['error' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->getPayload();
        $repository = $payload->all('repository');
        $name = strtolower((string) ($repository['full_name'] ?? ''));
        $connectors = '' === $name ? [] : array_filter(
            $this->entityManager->getRepository(Connector::class)->findBy(['mode' => ConnectorMode::GitHub]),
            static fn (Connector $connector): bool => strtolower(trim((string) ($connector->getArguments()['repository'] ?? ''), '/ ')) === $name
                && null !== $connector->getProject() && !$connector->getProject()->isArchived(),
        );

        $queued = 0;
        foreach ($connectors as $connector) {
            $message = match ($request->headers->get('X-GitHub-Event')) {
                'push' => $payload->getString('ref') === 'refs/heads/'.($connector->getArguments()['branch'] ?? $repository['default_branch'] ?? '')
                    ? new SyncConnector($connector->getId(), ConnectorRun::WEBHOOK)
                    : null,
                'pull_request' => $this->review($connector, $payload->getString('action'), $payload->all('pull_request')),
                default => null,
            };
            if (null !== $message) {
                $this->bus->dispatch($message);
                ++$queued;
            }
        }

        return $this->json(['queued' => $queued], Response::HTTP_ACCEPTED);
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    private function review(Connector $connector, string $action, array $pullRequest): ?ReviewPullRequest
    {
        $wanted = true === ($connector->getArguments()['review'] ?? false) && \in_array($action, self::REVIEWED_ACTIONS, true);

        return $wanted && false === ($pullRequest['draft'] ?? false) && isset($pullRequest['number']) ? new ReviewPullRequest($connector->getId(), (int) $pullRequest['number']) : null;
    }
}
