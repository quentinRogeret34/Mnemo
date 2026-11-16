<?php

namespace App\Tests\Garden;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Garden\DuplicateFinder;
use App\Garden\GardenChecker;
use App\Ingest\GraphifyImporter;
use App\Memory\MemoryStore;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GardenTest extends WebTestCase
{
    use LlmTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($this->project, __DIR__.'/../Fixtures/graphify/graph-v1.json');
    }

    public function testReportsDriftWithoutChangingAnything(): void
    {
        $store = self::getContainer()->get(MemoryStore::class);
        $orphan = $store->add('Mémoire orpheline.', MemoryType::Fact, 'manual');
        $unlinked = $store->add('Mémoire déliée.', MemoryType::Fact, 'manual');
        $unlinked->addProject($this->project);
        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/domain/catalog.ts']);
        $rule = new Node($this->project, NodeType::BusinessRule, 'RG-001', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'to_review']);
        $this->entityManager->persist($rule);
        $this->entityManager->persist(new Edge($rule, $file, 'implemented_by', Confidence::Inferred, EdgeOrigin::Llm));
        $this->entityManager->flush();
        $store->link($unlinked, $file);
        $this->entityManager->flush();
        $store->unlink($unlinked, $this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $unlinked])->getId());
        $this->entityManager->flush();
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, label, metadata::text, updated_at FROM node ORDER BY id');

        $findings = array_column(self::getContainer()->get(GardenChecker::class)->check($this->project), null, 'key');

        self::assertSame(['Mémoire orpheline.'], array_column($findings['orphan_memories']['items'], 'label'));
        self::assertSame(['Mémoire déliée.'], array_column($findings['unlinked_memories']['items'], 'label'));
        self::assertSame(['Mémoire déliée.'], array_column($findings['unverified_memories']['items'], 'label'));
        foreach (['rules_to_review', 'rules_without_source', 'rules_not_analyzed'] as $key) {
            self::assertSame(['RG-001'], array_column($findings[$key]['items'], 'code'), $key);
        }
        self::assertArrayNotHasKey('rules_without_code', $findings);
        self::assertContains('implemented_by : 1', array_column($findings['inferred_edges']['items'], 'label'));
        self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, label, metadata::text, updated_at FROM node ORDER BY id'));

        $this->client->request('GET', '/projects/demo/garden');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#finding-orphan_memories', 'Mémoires sans projet ni lien');
        self::assertSame($orphan->getId(), (int) basename($this->client->getCrawler()->filter('#finding-orphan_memories')->closest('section')->filter('a')->attr('href')));
    }

    public function testDuplicatesAreProposedOnceAndOnlyForListedMemories(): void
    {
        $store = self::getContainer()->get(MemoryStore::class);
        $ids = [];
        foreach (['Les remises sont plafonnées à 10 %.', 'Remise maximale : 10 %.', 'Les remises peuvent atteindre 30 %.'] as $text) {
            $memory = $store->add($text, MemoryType::Convention, 'manual');
            $memory->addProject($this->project);
            $this->entityManager->flush();
            $ids[] = $memory->getId();
        }
        $this->scriptLlm(static fn (): array => ['pairs' => [
            ['a_id' => $ids[1], 'b_id' => $ids[0], 'kind' => 'duplicate', 'reason' => 'Même plafond.'],
            ['a_id' => $ids[0], 'b_id' => $ids[2], 'kind' => 'contradiction', 'reason' => '10 % contre 30 %.'],
            ['a_id' => $ids[0], 'b_id' => 999, 'kind' => 'duplicate', 'reason' => 'Inventé.'],
        ]]);

        $first = self::getContainer()->get(DuplicateFinder::class)->find($this->project);
        $second = self::getContainer()->get(DuplicateFinder::class)->find($this->project);

        self::assertSame(['compared' => 3, 'proposed' => 2, 'already_proposed' => 0], $first);
        self::assertSame(['compared' => 3, 'proposed' => 0, 'already_proposed' => 2], $second);
        self::assertSame(1, $this->entityManager->getRepository(Proposal::class)->count(['kind' => ProposalKind::Contradiction]));
    }

    public function testDuplicateSearchFromThePageReportsTheComparison(): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/garden');
        $this->client->submit($crawler->selectButton('Chercher doublons et contradictions')->form());

        self::assertResponseRedirects('/projects/demo/garden');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', '0 mémoire comparée');
    }
}
