<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\MemoryType;
use App\Enum\UserStatus;
use App\Ingest\GraphifyImporter;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PaletteTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector, api_key, app_user, user_group RESTART IDENTITY CASCADE');
        $demo = new Project('demo', 'Demo project', '/repos/demo');
        $other = new Project('other', 'Other project', '/repos/other');
        $this->entityManager->persist($demo);
        $this->entityManager->persist($other);
        $store = self::getContainer()->get(MemoryStore::class);
        $store->add('Remise demo : recalculée au panier.', MemoryType::Decision, 'manual')->addProject($demo);
        $store->add('Remise other : jamais cumulée.', MemoryType::Decision, 'manual')->addProject($other);
        $store->add('Remise privée de quelqu\'un d\'autre.', MemoryType::Fact, 'manual', ['visibility' => 'private', 'owner_id' => 999])->addProject($demo);
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($demo, __DIR__.'/../Fixtures/graphify/graph-v1.json');
    }

    public function testMemoriesAndFilesAreSearchedAsTheUserTypes(): void
    {
        self::assertSame(['memories' => [], 'files' => []], $this->search('/palette?q=r'));

        $results = $this->search('/palette?q=remise');
        self::assertContains('Remise demo : recalculée au panier.', array_column($results['memories'], 'label'));
        self::assertContains('Remise other : jamais cumulée.', array_column($results['memories'], 'label'));
        self::assertSame([], $results['files']);

        $results = $this->search('/palette?q=catalog&project=demo');
        self::assertSame([], $results['memories']);
        self::assertSame(['src/domain/catalog.ts'], array_column($results['files'], 'label'));
        self::assertSame('/projects/demo/files/src/domain/catalog.ts', $results['files'][0]['url']);

        $crawler = $this->client->request('GET', '/');
        self::assertCount(1, $crawler->filter('dialog#palette[closedby="any"] input[role="combobox"][aria-controls="palette-list"]'));
        self::assertSelectorTextContains('#palette', 'Other project');
        self::assertSelectorTextContains('#palette', 'Connexions');
    }

    public function testACallerLimitedToSomeProjectsOnlySearchesThem(): void
    {
        $bob = new User('bob@example.com', 'Bob', 'github', 'bob');
        $bob->setStatus(UserStatus::Active);
        $back = new UserGroup('Back');
        $back->setUsers([$bob]);
        $back->getProjects()->add($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo']));
        $this->entityManager->persist($bob);
        $this->entityManager->persist($back);
        $this->entityManager->flush();
        $this->client->loginUser($bob);

        self::assertSame([], $this->search('/palette?q=remise')['memories']);
        self::assertSame(['Remise demo : recalculée au panier.'], array_column($this->search('/palette?q=remise&project=demo')['memories'], 'label'));
        $this->client->request('GET', '/palette?q=remise&project=other');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/projects/demo');
        $palette = $crawler->filter('#palette');
        self::assertStringContainsString('Demo project', $palette->text());
        self::assertStringNotContainsString('Other project', $palette->text());
        self::assertStringNotContainsString('Connexions', $palette->text());
        self::assertSame('demo', $palette->attr('data-palette-project-value'));
    }

    /**
     * @return array{memories: list<array{label: string, url: string}>, files: list<array{label: string, url: string}>}
     */
    private function search(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }
}
