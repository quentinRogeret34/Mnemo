<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Ingest\GraphifyImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GraphControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo project', '/repos/demo');
        $entityManager->persist($project);
        $entityManager->flush();

        self::getContainer()->get(GraphifyImporter::class)->import($project, __DIR__.'/../Fixtures/graphify/graph-v1.json');
    }

    public function testProjectIndexListsProjectsWithCounts(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('.content .row');
        self::assertCount(1, $row);
        self::assertStringContainsString('Demo project', $row->text());
        self::assertSame(
            ['2 fichiers', '3 symboles', '6 arêtes', 'aaaaaaa'],
            $row->filter('.row__meta > span')->each(static fn ($stat): string => $stat->text()),
        );
    }

    public function testProjectPageSearchesFilesBySymbolName(): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/code?query=planmerges');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('.file-row');
        self::assertCount(1, $rows);
        self::assertStringContainsString('src/domain/catalog.ts', $rows->text());
        self::assertSame('planMerges()', $rows->filter('.file-row__match')->text());
    }

    public function testProjectPageListsAllFilesWithoutQuery(): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/code');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.file-row'));
    }

    public function testFileSheetShowsSymbolsAndIncomingEdges(): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/files/src/domain/catalog.ts');

        self::assertResponseIsSuccessful();
        self::assertSame(['L10Merge', 'L42planMerges()'], $crawler->filter('.symbols li')->each(static fn ($li): string => str_replace(' ', '', $li->text())));

        $incoming = $crawler->filter('section[aria-labelledby="incoming-title"] tbody tr');
        self::assertCount(3, $incoming);
        self::assertSame('INFERRED', $incoming->filter('.conf--inferred')->text());
        self::assertSame('/projects/demo/files/src/main.ts', $incoming->first()->filter('.edges__other a')->attr('href'));

        self::assertSelectorTextContains('section[aria-labelledby="outgoing-title"]', 'Aucune dépendance');
    }

    public function testNeighbourhoodFoldsSymbolEdgesOntoFiles(): void
    {
        $this->client->request('GET', '/projects/demo/graph/src/main.ts?depth=1');

        self::assertResponseIsSuccessful();
        $graph = json_decode($this->client->getResponse()->getContent(), true);

        self::assertEqualsCanonicalizing(['src/main.ts', 'src/domain/catalog.ts'], array_column($graph['nodes'], 'id'));
        self::assertCount(1, $graph['edges']);
        self::assertSame('src/main.ts', $graph['edges'][0]['source']);
        self::assertSame(3, $graph['edges'][0]['count']);
        self::assertFalse($graph['edges'][0]['inferred']);
        self::assertEqualsCanonicalizing(['calls', 'imports_from', 'indirect_call'], $graph['edges'][0]['relations']);
    }

    public function testUnknownFileIsNotFound(): void
    {
        $this->client->request('GET', '/projects/demo/files/src/missing.ts');

        self::assertResponseStatusCodeSame(404);
    }
}
