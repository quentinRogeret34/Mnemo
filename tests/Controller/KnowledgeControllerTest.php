<?php

namespace App\Tests\Controller;

use App\Demo\DemoData;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use App\Ingest\GraphifyImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class KnowledgeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($this->project, __DIR__.'/../Fixtures/graphify/graph-v1.json');
    }

    public function testUploadedMarkdownIsListedAndRenderedWithHtmlEscaped(): void
    {
        $markdown = tempnam(sys_get_temp_dir(), 'doc').'.md';
        file_put_contents($markdown, "# Règles de facturation\n\n| Seuil | Valeur |\n|---|---|\n| Remise | 10 % |\n\n<script>alert(1)</script>\n");

        $crawler = $this->client->request('GET', '/projects/demo');
        $form = $crawler->selectButton('Ajouter')->form();
        $form['document_upload[files]'][0]->upload($markdown);
        $this->client->submit($form);

        self::assertResponseRedirects('/projects/demo');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', '1 document ajouté');
        self::assertSame('Règles de facturation', $crawler->filter('.content .row__title > span')->first()->text());

        $crawler = $this->client->click($crawler->filter('.content .row')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.doc__title', 'Règles de facturation');
        self::assertCount(1, $crawler->filter('.prose table'));
        self::assertCount(0, $crawler->filter('.prose script'));
        self::assertStringContainsString('&lt;script&gt;', $crawler->filter('.prose')->html());
    }

    public function testUploadRejectsUnsupportedFormat(): void
    {
        $text = tempnam(sys_get_temp_dir(), 'doc').'.exe';
        file_put_contents($text, 'MZ');

        $crawler = $this->client->request('GET', '/projects/demo');
        $form = $crawler->selectButton('Ajouter')->form();
        $form['document_upload[files]'][0]->upload($text);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'Format non pris en charge');
    }

    public function testRulePagesLinkDocumentsAndCode(): void
    {
        $this->givenRuleImplementedBy('src/domain/catalog.ts');

        $crawler = $this->client->request('GET', '/projects/demo/rules?status=to_review');
        self::assertResponseIsSuccessful();
        self::assertSame('RG-042', $crawler->filter('.content .row .code-chip')->text());

        $crawler = $this->client->request('GET', '/projects/demo/rules/RG-042');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.callout', 'Une remise ne dépasse jamais 10 %.');
        self::assertSame('/projects/demo/files/src/domain/catalog.ts', $crawler->filter('section[aria-labelledby="impl-title"] .row')->attr('href'));
        self::assertSelectorTextContains('section[aria-labelledby="sources-title"]', 'Conditions générales');

        $crawler = $this->client->request('GET', '/projects/demo/files/src/domain/catalog.ts');
        self::assertSame('RG-042', $crawler->filter('section[aria-labelledby="rules-title"] .code-chip')->text());
    }

    public function testDemoDataLoadsAndClearsOnlyItself(): void
    {
        $demoData = self::getContainer()->get(DemoData::class);

        $result = $demoData->load($this->project);
        self::assertSame(3, $result['documents']);
        self::assertSame(7, $result['rules']);
        self::assertSame(8, $result['memories']);
        self::assertSame(2, $result['proposals']);

        $this->givenRuleImplementedBy('src/main.ts');
        self::assertSame(20, $demoData->clear($this->project));

        $remaining = $this->entityManager->getRepository(Node::class)->findBy(['type' => [NodeType::BusinessRule, NodeType::Document]], ['type' => 'ASC']);
        self::assertSame(['RG-042', 'Conditions générales'], array_map(static fn (Node $node): string => NodeType::BusinessRule === $node->getType() ? $node->getExternalId() : $node->getLabel(), $remaining));
    }

    public function testTheDemoCommandCreatesAShopWhoseRulesAreLinkedToItsCode(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:demo'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('3 documents, 7 rules, 8 memories, 2 proposals to review, 13 rule-to-code links', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $crawler = $this->client->request('GET', '/projects/acme-shop/rules/RG-001');
        self::assertSelectorTextContains('.page__title', 'Discount cap');
        self::assertCount(2, $crawler->filter('section[aria-labelledby="impl-title"] .row'));
        $this->client->request('GET', '/proposals?project=acme-shop');
        self::assertSelectorCount(2, '.proposal-row');
    }

    private function givenRuleImplementedBy(string $path): void
    {
        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['project' => $this->project, 'type' => NodeType::CodeFile, 'path' => $path]);
        $rule = new Node($this->project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'to_review']);
        $document = new Node(null, NodeType::Document, 'cgv', 'Conditions générales', 'cgv.pdf', null, ['format' => 'pdf', 'size' => 1000, 'storage' => 'cgv.pdf']);

        $this->entityManager->persist($rule);
        $this->entityManager->persist($document);
        $this->entityManager->persist(new Edge($document, $rule, 'defines', Confidence::Inferred, EdgeOrigin::Llm));
        $this->entityManager->persist(new Edge($rule, $file, 'implemented_by', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->flush();
    }
}
