<?php

namespace App\Tests\Verify;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Ingest\GraphifyImporter;
use App\Memory\MemoryStore;
use App\Tests\LlmTestTrait;
use App\Verify\MemoryVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MemoryVerifierTest extends WebTestCase
{
    use LlmTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;
    private Node $rule;

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

        $this->rule = new Node($this->project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'validated']);
        $this->entityManager->persist($this->rule);
        $this->entityManager->flush();
    }

    public function testACitedPathMissingFromTheCodeGraphRefutesWithoutLlm(): void
    {
        $this->forbidLlm();
        $memory = $this->givenMemory('Les remises sont calculées dans src/domain/pricing.ts, pas dans le panier.');

        $verification = self::getContainer()->get(MemoryVerifier::class)->verify($memory, $this->project);

        self::assertSame('refuted', $verification['verdict']);
        self::assertSame('graph', $verification['tier']);
        self::assertStringContainsString('src/domain/pricing.ts', $verification['reason']);
        self::assertSame(1, $this->entityManager->getRepository(Proposal::class)->count(['kind' => ProposalKind::Refuted]));
    }

    public function testPathsWithAnExtensionUnknownToTheGraphProveNothing(): void
    {
        $this->scriptLlm(static fn (): array => ['verdict' => 'unverified', 'source_ids' => [], 'reason' => 'Rien ne le dit.']);
        $memory = $this->givenMemory('La configuration vit dans config/packages/ai.yaml.');

        $verification = self::getContainer()->get(MemoryVerifier::class)->verify($memory, $this->project);

        self::assertSame(['verdict' => 'unverified', 'tier' => 'knowledge'], array_intersect_key($verification, ['verdict' => 1, 'tier' => 1]));
        self::assertSame(0, $this->entityManager->getRepository(Proposal::class)->count([]));
    }

    public function testTheLlmCanOnlyCiteTheSourcesItWasGiven(): void
    {
        $memory = $this->givenMemory('Les remises sont plafonnées à 10 % dans src/domain/catalog.ts.');
        $this->scriptLlm(fn (): array => ['verdict' => 'verified', 'source_ids' => [$this->rule->getId(), 9999], 'reason' => 'La règle le dit.']);

        $verification = self::getContainer()->get(MemoryVerifier::class)->verify($memory, $this->project);

        self::assertSame('verified', $verification['verdict']);
        self::assertSame([['id' => $this->rule->getId(), 'type' => 'business_rule', 'label' => 'RG-042 Remise plafonnée']], $verification['sources']);
        self::assertStringContainsString('src/domain/catalog.ts (code_file)', $this->llmCalls[0]['user']);
        self::assertStringContainsString('Une remise ne dépasse jamais 10 %.', $this->llmCalls[0]['user']);

        $updatedAt = $memory->getUpdatedAt();
        $this->entityManager->clear();
        $memory = $this->entityManager->find(Node::class, $memory->getId());
        self::assertSame('Les remises sont plafonnées à 10 % dans src/domain/catalog.ts.', $memory->getMetadata()['verification']['text']);
        self::assertSame($updatedAt->format('U'), $memory->getUpdatedAt()->format('U'), 'A verification is not an edit.');
    }

    public function testAVerdictWithoutValidSourceIsUnverifiedAndCheckedMemoriesAreSkipped(): void
    {
        $this->givenMemory('Les remises sont plafonnées.');
        $this->scriptLlm(static fn (): array => ['verdict' => 'refuted', 'source_ids' => [9999], 'reason' => 'Inventé.']);

        $first = self::getContainer()->get(MemoryVerifier::class)->verifyProject($this->project, 10);
        $second = self::getContainer()->get(MemoryVerifier::class)->verifyProject($this->project, 10);

        self::assertSame(['checked' => 1, 'verified' => 0, 'refuted' => 0, 'conflict' => 0, 'unverified' => 1], $first);
        self::assertSame(0, $second['checked']);
        self::assertSame(0, $this->entityManager->getRepository(Proposal::class)->count([]));
    }

    public function testVerifyButtonShowsTheVerdict(): void
    {
        $memory = $this->givenMemory('Les remises sont plafonnées à 10 %.');
        $this->scriptLlm(fn (): array => ['verdict' => 'verified', 'source_ids' => [$this->rule->getId()], 'reason' => 'La règle RG-042 le dit.']);

        $crawler = $this->client->request('GET', '/memories/'.$memory->getId());
        self::assertSelectorTextContains('section[aria-labelledby="verification-title"] .pill', 'Jamais vérifiée');
        $this->client->submit($crawler->selectButton('Vérifier')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash', 'vérifiée');
        self::assertSelectorTextContains('.verification__reason', 'La règle RG-042 le dit.');
        self::assertSelectorTextContains('section[aria-labelledby="verification-title"] .pill--success', 'Vérifiée');
    }

    private function givenMemory(string $text): Node
    {
        $store = self::getContainer()->get(MemoryStore::class);
        $memory = $store->add($text, MemoryType::Fact, 'manual');
        $memory->addProject($this->project);
        $this->entityManager->flush();
        $this->entityManager->persist(new Edge($memory, $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/domain/catalog.ts']), 'about', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->flush();

        return $memory;
    }
}
