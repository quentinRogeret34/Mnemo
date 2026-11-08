<?php

namespace App\Tests\Controller;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\EdgeStatus;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Ingest\GraphifyImporter;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class MemoryTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($this->project, __DIR__.'/../Fixtures/graphify/graph-v1.json');
    }

    public function testMemoryCreatedFromAProjectIsAttachedAndListed(): void
    {
        $crawler = $this->client->request('GET', '/memories?project=demo');
        $this->client->submit($crawler->selectButton('Ajouter la mémoire')->form([
            'memory_form[text]' => '  Les remises sont plafonnées à 10 %.  ',
            'memory_form[type]' => 'convention',
        ]));

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSame('Les remises sont plafonnées à 10 %.', $this->client->getCrawler()->filter('#memory-text')->text());

        $memory = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::Memory]);
        self::assertSame(['Demo project'], $memory->getProjects()->map(static fn (Project $project): string => $project->getName())->getValues());
        self::assertSame('manual', $memory->getMetadata()['source']);
        self::assertSame('ADD', $memory->getMetadata()['history'][0]['event']);

        $crawler = $this->client->request('GET', '/memories?type=convention&q=remises');
        self::assertCount(1, $crawler->filter('.content .row--memory'));
        $crawler = $this->client->request('GET', '/memories?type=decision');
        self::assertCount(0, $crawler->filter('.content .row--memory'));
    }

    public function testEmptyMemoryIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/memories');
        $this->client->submit($crawler->selectButton('Ajouter la mémoire')->form(['memory_form[text]' => '   ']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'Écrire la mémoire.');
    }

    public function testEditorUpdatesTextKeepsHistoryAndLinksNodes(): void
    {
        $memory = $this->givenMemory();
        $component = $this->createLiveComponent('MemoryEditor', ['memory' => $memory]);

        $component->set('text', 'Le panier recalcule les remises à chaque ajout.');
        $component->set('type', 'fact');
        $component->call('save');

        $component->set('linkQuery', 'catalog');
        $results = $component->render()->crawler()->filter('.link-search__item');
        self::assertSame(['src/domain/catalog.ts'], $results->each(static fn ($item): string => $item->filter('.mono')->text()));

        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/domain/catalog.ts']);
        $component->call('link', ['nodeId' => $file->getId()]);

        $this->entityManager->clear();
        $memory = $this->entityManager->find(Node::class, $memory->getId());
        self::assertSame('Le panier recalcule les remises à chaque ajout.', $memory->getLabel());
        self::assertSame(MemoryType::Fact->value, $memory->getMetadata()['type']);
        self::assertSame(['ADD', 'UPDATE'], array_column($memory->getMetadata()['history'], 'event'));
        self::assertSame('Panier : remises recalculées.', $memory->getMetadata()['history'][1]['previous']);

        $crawler = $this->client->request('GET', '/projects/demo/files/src/domain/catalog.ts');
        self::assertSame('Le panier recalcule les remises à chaque ajout.', $crawler->filter('section[aria-labelledby="memories-title"] .memory-text')->text());

        $edge = $this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $memory, 'relation' => 'about']);
        $component->call('unlink', ['edgeId' => $edge->getId()]);
        $this->entityManager->clear();
        self::assertSame(EdgeStatus::Rejected, $this->entityManager->find(Edge::class, $edge->getId())->getStatus());
    }

    public function testCodeOfANonAttachedProjectCannotBeLinked(): void
    {
        $memory = $this->givenMemory();
        $memory->removeProject($this->project);
        $this->entityManager->flush();
        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/main.ts']);

        $this->expectException(\InvalidArgumentException::class);
        $this->createLiveComponent('MemoryEditor', ['memory' => $memory])->call('link', ['nodeId' => $file->getId()]);
    }

    public function testDeleteRequiresTokenAndRemovesLinks(): void
    {
        $memory = $this->givenMemory();
        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/main.ts']);
        self::getContainer()->get(MemoryStore::class)->link($memory, $file);
        $this->entityManager->flush();
        $id = $memory->getId();

        $this->client->request('POST', "/memories/$id/delete", ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', "/memories/$id");
        $this->client->submit($crawler->selectButton('Supprimer la mémoire')->form());
        self::assertResponseRedirects('/memories');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Node::class, $id));
        self::assertSame(0, $this->entityManager->getRepository(Edge::class)->count(['relation' => 'about']));
    }

    private function givenMemory(): Node
    {
        $memory = self::getContainer()->get(MemoryStore::class)->add('Panier : remises recalculées.', MemoryType::Decision, 'manual');
        $memory->addProject($this->project);
        $this->entityManager->flush();

        return $memory;
    }
}
