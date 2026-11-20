<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Enum\MemoryType;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PaginationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, mcp_call RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($project);
        $store = self::getContainer()->get(MemoryStore::class);
        for ($i = 1; $i <= 55; ++$i) {
            $store->add(\sprintf('Convention numéro %02d.', $i), MemoryType::Convention, 'manual')->addProject($project);
        }
        $this->entityManager->flush();
    }

    public function testMemoriesAreSplitInPagesThatKeepTheFilters(): void
    {
        $crawler = $this->client->request('GET', '/memories?project=demo&type=convention');
        self::assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('.content .row--memory'));
        self::assertStringContainsString('55', $crawler->filter('.view-head__meta')->text());
        self::assertStringContainsString('Page 1 sur 2', $crawler->filter('.pager__status')->text());
        self::assertCount(0, $crawler->filter('.pager a[rel="prev"]'));

        $crawler = $this->client->click($crawler->filter('.pager a[rel="next"]')->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('type=convention', $this->client->getRequest()->getUri());
        self::assertCount(5, $crawler->filter('.content .row--memory'));
        self::assertCount(0, $crawler->filter('.pager a[rel="next"]'));

        $crawler = $this->client->request('GET', '/memories?page=9');
        self::assertCount(0, $crawler->filter('.content .row--memory'));
        self::assertStringEndsWith('page=1', $crawler->filter('.pager a[rel="prev"]')->attr('href'));

        self::assertCount(0, $this->client->request('GET', '/memories?q=num%C3%A9ro+07')->filter('.pager'));
    }

    public function testTheActivityPageShowsTheTenLatestEntriesAndLinksToTheWholeFeed(): void
    {
        $crawler = $this->client->request('GET', '/activity?project=demo&period=30d');
        self::assertResponseIsSuccessful();
        self::assertCount(10, $crawler->filter('section[aria-labelledby="feed-title"] .event'));
        self::assertStringContainsString('Convention numéro 55.', $crawler->filter('section[aria-labelledby="feed-title"] .event')->first()->text());

        $crawler = $this->client->click($crawler->filter('section[aria-labelledby="feed-title"] a[href*="/activity/feed"]')->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('project=demo', $this->client->getRequest()->getUri());
        self::assertStringContainsString('period=30d', $this->client->getRequest()->getUri());
        self::assertCount(50, $crawler->filter('section[aria-labelledby="feed-title"] .event'));

        $crawler = $this->client->click($crawler->filter('.pager a[rel="next"]')->link());
        self::assertCount(5, $crawler->filter('section[aria-labelledby="feed-title"] .event'));
        self::assertStringContainsString('project=demo', $this->client->getRequest()->getUri());
    }

    public function testTheWholeFeedIsFilteredByTypeAndText(): void
    {
        $crawler = $this->client->request('GET', '/activity/feed?project=demo&type=memory&q=num%C3%A9ro+07');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('section[aria-labelledby="feed-title"] .event'));
        self::assertCount(0, $crawler->filter('.pager'));

        $crawler = $this->client->request('GET', '/activity/feed?project=demo&type=commit');
        self::assertCount(0, $crawler->filter('section[aria-labelledby="feed-title"] .event'));
        self::assertCount(1, $crawler->filter('.block__empty'));
    }

    public function testTheLatestMcpCallsAreSplitInPages(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO mcp_call (created_at, tool, results, memories, failed, duration_ms) SELECT NOW() - make_interval(mins => i), 'search', i, 0, false, 5 FROM generate_series(1, 23) AS i",
        );

        $crawler = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('section[aria-labelledby="recent-title"] tbody tr'));

        $crawler = $this->client->click($crawler->filter('section[aria-labelledby="recent-title"] .pager a[rel="next"]')->link());
        self::assertCount(3, $crawler->filter('section[aria-labelledby="recent-title"] tbody tr'));
        self::assertSame('23', $crawler->filter('section[aria-labelledby="recent-title"] tbody tr')->last()->filter('td')->eq(3)->text());
    }
}
