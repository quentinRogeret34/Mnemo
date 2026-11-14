<?php

namespace App\Tests\Controller;

use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Message\ReviewPullRequest;
use App\Message\SyncConnector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GitHubWebhookControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connector $reviewed;
    private Connector $silent;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo project', '/repos/demo');
        $entityManager->persist($project);
        $this->reviewed = $this->connector($project, ['repository' => 'Acme/Shop', 'review' => true]);
        $this->silent = $this->connector($project, ['repository' => 'acme/shop']);
        $other = $this->connector($project, ['repository' => 'acme/other', 'review' => true]);
        foreach ([$this->reviewed, $this->silent, $other] as $connector) {
            $entityManager->persist($connector);
        }
        $entityManager->flush();
    }

    public function testAnUnsignedEventIsRefused(): void
    {
        $this->client->request('POST', '/webhooks/github', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'push', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], content: '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->queued());
    }

    public function testAPushOnTheDefaultBranchSynchronisesTheConnectorsOfTheRepository(): void
    {
        $this->send('push', ['ref' => 'refs/heads/feature', 'repository' => ['full_name' => 'acme/shop', 'default_branch' => 'main']]);
        self::assertSame([], $this->queued());

        $this->send('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/shop', 'default_branch' => 'main']]);
        self::assertResponseStatusCodeSame(202);
        self::assertEquals(
            [new SyncConnector($this->reviewed->getId(), ConnectorRun::WEBHOOK), new SyncConnector($this->silent->getId(), ConnectorRun::WEBHOOK)],
            $this->queued(),
        );
    }

    public function testAPullRequestIsReviewedWhereTheReviewIsEnabled(): void
    {
        $repository = ['full_name' => 'acme/shop', 'default_branch' => 'main'];
        $this->send('pull_request', ['action' => 'opened', 'pull_request' => ['number' => 12, 'draft' => true], 'repository' => $repository]);
        $this->send('pull_request', ['action' => 'closed', 'pull_request' => ['number' => 12, 'draft' => false], 'repository' => $repository]);
        self::assertSame([], $this->queued());

        $this->send('pull_request', ['action' => 'synchronize', 'pull_request' => ['number' => 12, 'draft' => false], 'repository' => $repository]);
        self::assertEquals([new ReviewPullRequest($this->reviewed->getId(), 12)], $this->queued());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function connector(Project $project, array $arguments): Connector
    {
        $connector = new Connector();
        $connector->setName($arguments['repository']);
        $connector->setMode(ConnectorMode::GitHub);
        $connector->setEndpoint('https://api.github.com');
        $connector->setArguments($arguments);
        $connector->setProject($project);

        return $connector;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(string $event, array $payload): void
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/webhooks/github', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'webhook-secret'),
        ], content: $body);
    }

    /**
     * @return list<object>
     */
    private function queued(): array
    {
        return array_map(static fn ($envelope): object => $envelope->getMessage(), self::getContainer()->get('messenger.transport.async')->getSent());
    }
}
