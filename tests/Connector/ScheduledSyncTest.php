<?php

namespace App\Tests\Connector;

use App\Connector\ConnectorRunner;
use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorSchedule;
use App\Enum\ConnectorTransport;
use App\Message\SyncConnector;
use App\Message\SyncDueConnectors;
use App\MessageHandler\SyncConnectorHandler;
use App\MessageHandler\SyncDueConnectorsHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

final class ScheduledSyncTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
    }

    public function testOnlyDueConnectorsAreQueuedAndTheirRunIsRecorded(): void
    {
        $daily = $this->connector('Quotidien', ConnectorSchedule::Daily);
        $this->connector('Manuel', ConnectorSchedule::Manual);

        self::getContainer()->get(SyncDueConnectorsHandler::class)(new SyncDueConnectors());
        $queued = array_map(static fn ($envelope): object => $envelope->getMessage(), self::getContainer()->get('messenger.transport.async')->getSent());
        self::assertEquals([new SyncConnector($daily->getId())], $queued);

        self::getContainer()->get(SyncConnectorHandler::class)($queued[0]);
        $run = $this->entityManager->getRepository(ConnectorRun::class)->findOneBy(['connector' => $daily]);
        self::assertSame(ConnectorRun::SCHEDULE, $run->getTrigger());
        self::assertSame(2, $run->getReport()['created']);

        self::getContainer()->get(SyncDueConnectorsHandler::class)(new SyncDueConnectors());
        self::assertCount(1, array_filter(self::getContainer()->get('messenger.transport.async')->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof SyncConnector));

        $crawler = $this->client->request('GET', '/activity?project=demo&period=1d');
        self::assertResponseIsSuccessful();
        self::assertSame(['Documents', '2'], [$crawler->filter('.tile__label')->text(), $crawler->filter('.tile__value')->text()]);
        self::assertCount(2, $crawler->filter('section[aria-labelledby="feed-title"] .row'));
        self::assertSame('planifié', $crawler->filter('section[aria-labelledby="runs-title"] tbody tr td')->eq(2)->text());
    }

    public function testTheConnectorsOfAnArchivedProjectAreLeftAlone(): void
    {
        $this->connector('Quotidien', ConnectorSchedule::Daily);
        $this->project->archive();
        $this->entityManager->flush();

        self::getContainer()->get(SyncDueConnectorsHandler::class)(new SyncDueConnectors());
        self::assertSame([], self::getContainer()->get('messenger.transport.async')->getSent());

        $this->project->restore();
        $this->entityManager->flush();
        self::getContainer()->get(SyncDueConnectorsHandler::class)(new SyncDueConnectors());
        self::assertCount(1, self::getContainer()->get('messenger.transport.async')->getSent());
    }

    public function testAConnectorIsNeverSynchronisedTwiceAtOnce(): void
    {
        $connector = $this->connector('Quotidien', ConnectorSchedule::Daily);
        $lock = self::getContainer()->get(LockFactory::class)->createLock('connector-sync-'.$connector->getId());
        self::assertTrue($lock->acquire());

        $report = self::getContainer()->get(ConnectorRunner::class)->sync($connector);
        self::assertStringContainsString('déjà en cours', $report['error']);
        self::assertSame(0, $this->entityManager->getRepository(ConnectorRun::class)->count());
        $lock->release();
    }

    private function connector(string $name, ConnectorSchedule $schedule): Connector
    {
        $connector = new Connector();
        $connector->setName($name);
        $connector->setTransport(ConnectorTransport::Stdio);
        $connector->setEndpoint('php tests/Fixtures/mcp/server.php');
        $connector->setMode(ConnectorMode::Resources);
        $connector->setProject($this->project);
        $connector->setSchedule($schedule);
        $this->entityManager->persist($connector);
        $this->entityManager->flush();

        return $connector;
    }
}
