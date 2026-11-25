<?php

namespace App\Tests\Controller;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\EdgeStatus;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Ingest\GraphifyImporter;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProposalTest extends WebTestCase
{
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

    public function testLearningIsAppliedWithTheEditedText(): void
    {
        $this->givenProposal(ProposalKind::Learning, $this->learning('Le catalogue est chargé au démarrage.'));

        $crawler = $this->client->request('GET', '/proposals?project=demo');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nav-item__count--alert', '1');
        $this->client->submit($crawler->selectButton('Appliquer')->form(['lesson' => 'Le catalogue est chargé une fois, au démarrage.']));

        self::assertResponseRedirects('/proposals?project=demo');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Le catalogue est chargé une fois, au démarrage.');
        self::assertSelectorTextContains('.block__empty', 'Rien à valider');

        $memory = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::Memory]);
        self::assertSame('Le catalogue est chargé une fois, au démarrage.', $memory->getLabel());
        self::assertSame(ProposalStatus::Applied, $this->entityManager->getRepository(Proposal::class)->findOneBy([])->getStatus());
    }

    public function testLearningIsQuickAppliedFromTheTable(): void
    {
        $this->givenProposal(ProposalKind::Learning, $this->learning('Le catalogue est chargé au démarrage.'));

        $crawler = $this->client->request('GET', '/proposals?project=demo');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.proposal-table .proposal-act--approve');
        self::assertSelectorExists('.proposal-table .proposal-act--reject');
        self::assertSelectorExists('.proposal-table .proposal-act--edit');
        $this->client->submit($crawler->selectButton('Valider rapidement')->form());

        self::assertResponseRedirects('/proposals?project=demo');
        $memory = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::Memory]);
        self::assertSame('Le catalogue est chargé au démarrage.', $memory->getLabel());
        self::assertSame(ProposalStatus::Applied, $this->entityManager->getRepository(Proposal::class)->findOneBy([])->getStatus());
    }

    public function testRejectedProposalChangesNothing(): void
    {
        $this->givenProposal(ProposalKind::Learning, $this->learning('Le catalogue est chargé au démarrage.'));

        $crawler = $this->client->request('GET', '/proposals');
        $this->client->submit($crawler->selectButton('Rejeter')->form());

        self::assertResponseRedirects('/proposals');
        self::assertSame(0, $this->entityManager->getRepository(Node::class)->count(['type' => NodeType::Memory]));
        self::assertSame(ProposalStatus::Rejected, $this->entityManager->getRepository(Proposal::class)->findOneBy([])->getStatus());
    }

    public function testRejectedProposalFromDrawerSavesReason(): void
    {
        $this->givenProposal(ProposalKind::Learning, $this->learning('Le catalogue est chargé au démarrage.'));

        $crawler = $this->client->request('GET', '/proposals');
        $drawerReject = $crawler->filter('.proposal-drawer button[formaction*="/reject"]')->first();
        $form = $drawerReject->form(['reason' => 'too_obvious']);
        $this->client->submit($form);

        self::assertResponseRedirects('/proposals');
        $proposal = $this->entityManager->getRepository(Proposal::class)->findOneBy([]);
        self::assertSame(ProposalStatus::Rejected, $proposal->getStatus());
        self::assertSame('too_obvious', $proposal->getPayload()['human_feedback']['rejected_reason'] ?? null);
    }

    public function testDuplicateMergesLinksIntoTheKeptMemory(): void
    {
        $store = self::getContainer()->get(MemoryStore::class);
        $kept = $store->add('Les remises sont plafonnées à 10 %.', MemoryType::Convention, 'manual');
        $dropped = $store->add('Remise maximale : 10 %.', MemoryType::Convention, 'manual');
        $dropped->addProject($this->project);
        $this->entityManager->flush();
        $store->link($dropped, $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/domain/catalog.ts']));
        $this->entityManager->flush();
        $this->givenProposal(ProposalKind::Duplicate, ['a_id' => $kept->getId(), 'a_text' => $kept->getLabel(), 'b_id' => $dropped->getId(), 'b_text' => $dropped->getLabel(), 'reason' => 'Même plafond.']);

        $crawler = $this->client->request('GET', '/proposals');
        $form = $crawler->selectButton('Appliquer')->form();
        $form['keep']->select((string) $kept->getId());
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->entityManager->clear();
        $kept = $this->entityManager->find(Node::class, $kept->getId());
        $dropped = $this->entityManager->find(Node::class, $dropped->getId());
        self::assertSame(MemoryStore::STATUS_SUPERSEDED, $dropped->getMetadata()['status']);
        self::assertSame(['demo'], $kept->getProjects()->map(static fn (Project $project): string => $project->getSlug())->getValues());
        $edge = $this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $kept, 'relation' => 'about']);
        self::assertSame('src/domain/catalog.ts', $edge->getTarget()->getPath());
        self::assertSame(EdgeStatus::Active, $edge->getStatus());

        $crawler = $this->client->request('GET', '/memories/'.$dropped->getId());
        self::assertSelectorTextContains('.archived-banner', 'Remplacée par Les remises sont plafonnées à 10 %.');
        $crawler = $this->client->request('GET', '/memories');
        self::assertCount(1, $crawler->filter('.content .row--memory'));
    }

    public function testProposalOnAnArchivedMemoryCannotBeApplied(): void
    {
        $store = self::getContainer()->get(MemoryStore::class);
        $memory = $store->add('Les remises sont plafonnées à 10 %.', MemoryType::Convention, 'manual');
        $this->entityManager->flush();
        $store->archive($memory, MemoryStore::STATUS_RETRACTED);
        $this->entityManager->flush();
        $this->givenProposal(ProposalKind::Refuted, ['memory_id' => $memory->getId(), 'memory_text' => $memory->getLabel(), 'verdict' => 'refuted', 'reason' => 'x', 'sources' => []]);

        $crawler = $this->client->request('GET', '/proposals');
        $this->client->submit($crawler->selectButton('Retirer la mémoire')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash--error', 'archivée entre-temps');
        self::assertSame(ProposalStatus::Pending, $this->entityManager->getRepository(Proposal::class)->findOneBy([])->getStatus());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function givenProposal(ProposalKind $kind, array $payload): void
    {
        $this->entityManager->persist(new Proposal($this->project, $kind, $payload));
        $this->entityManager->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function learning(string $lesson): array
    {
        return [
            'lesson' => $lesson, 'evidence' => 'source', 'type' => 'fact', 'score' => 7.5,
            'axes' => ['durability' => 8, 'reuse' => 7, 'project_fit' => 8, 'risk' => 7],
            'files' => ['src/domain/catalog.ts'], 'rules' => [], 'adr' => [],
            'reconciliation' => 'new', 'target_id' => null, 'target_text' => null, 'reason' => '',
            'source' => ['kind' => 'conversation', 'label' => 'test'],
        ];
    }
}
