<?php

namespace App\Tests\Alignment;

use App\Document\DocumentStorage;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AlignmentReportTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
    }

    public function testMatrixComputesDeterministicStatesScoresAndFilters(): void
    {
        $storage = self::getContainer()->get(DocumentStorage::class);
        $doc = $storage->storeContent(null, "# Spec\n\nRègles métier.", 'Spec produit', 'md');
        $doc->addProject($this->project);

        $file = new Node($this->project, NodeType::CodeFile, 'file:src/Order/Checkout.php', 'Checkout.php', 'src/Order/Checkout.php');
        $ticket = new Node($this->project, NodeType::Ticket, '#108', 'Mise à jour plafond remise', null, null, ['url' => 'https://github.com/acme/demo/pull/108']);
        $this->entityManager->persist($file);
        $this->entityManager->persist($ticket);

        // 1. Fully aligned rule (100%).
        $aligned = new Node($this->project, NodeType::BusinessRule, 'RG-001', 'Remise plafonnée', null, null, [
            'statement' => 'Plafond à 10 %.',
            'status' => 'validated',
            'shadow_areas' => [],
            'shadow_checked_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
        $this->entityManager->persist($aligned);
        $this->entityManager->persist(new Edge($doc, $aligned, 'defines', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->persist(new Edge($aligned, $file, 'implemented_by', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->persist(new Edge($ticket, $aligned, 'mentions', Confidence::Extracted, EdgeOrigin::Git));

        // 2. Unlinked rule (no source, no code, to_review).
        $unlinked = new Node($this->project, NodeType::BusinessRule, 'RG-002', 'Frais de port offerts', null, null, [
            'statement' => 'Offerts dès 80 €.',
            'status' => 'to_review',
        ]);
        $this->entityManager->persist($unlinked);

        // 3. Drift rule (blocking shadow area + refuted memory).
        $drift = new Node($this->project, NodeType::BusinessRule, 'RG-003', 'Annulation commande', null, null, [
            'statement' => 'Remboursement sous 5 jours.',
            'status' => 'validated',
            'shadow_checked_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'shadow_areas' => [[
                'category' => 'missing_edge_case',
                'gap' => 'Jours ouvrés ou calendaires.',
                'question' => '5 jours ouvrés ou calendaires ?',
                'severity' => 'blocker',
            ]],
        ]);
        $this->entityManager->persist($drift);
        $this->entityManager->persist(new Edge($doc, $drift, 'defines', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->persist(new Edge($drift, $file, 'implemented_by', Confidence::Inferred, EdgeOrigin::Llm));

        $refutedMemory = self::getContainer()->get(MemoryStore::class)->add('Le remboursement est instantané.', MemoryType::Fact, 'manual', [
            'verification' => ['verdict' => 'refuted', 'reason' => 'Contredit RG-003', 'tier' => 'knowledge', 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'text' => 'Le remboursement est instantané.', 'sources' => []],
        ]);
        $refutedMemory->addProject($this->project);
        $this->entityManager->persist(new Edge($refutedMemory, $drift, 'about', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->flush();

        // Project matrix: drift first, then unlinked, then aligned.
        $crawler = $this->client->request('GET', '/projects/demo/alignment');
        self::assertResponseIsSuccessful();
        $codes = $crawler->filter('.alignment-rule .code-chip')->each(static fn ($node): string => $node->text());
        self::assertSame(['RG-003', 'RG-002', 'RG-001'], $codes);
        self::assertSelectorTextContains('tbody', '#108');
        self::assertSelectorTextContains('tbody', '1 bloquant');
        self::assertSelectorTextContains('tbody', '1 mémoire réfutée');
        self::assertSelectorTextContains('tbody', '100 %');

        // Filter: drift only.
        $crawler = $this->client->request('GET', '/projects/demo/alignment?filter=drift');
        self::assertResponseIsSuccessful();
        self::assertSame(['RG-003'], $crawler->filter('.alignment-rule .code-chip')->each(static fn ($node): string => $node->text()));

        // Filter: aligned only.
        $crawler = $this->client->request('GET', '/projects/demo/alignment?filter=aligned');
        self::assertResponseIsSuccessful();
        self::assertSame(['RG-001'], $crawler->filter('.alignment-rule .code-chip')->each(static fn ($node): string => $node->text()));

        // Filter: unlinked only.
        $crawler = $this->client->request('GET', '/projects/demo/alignment?filter=unlinked');
        self::assertResponseIsSuccessful();
        self::assertSame(['RG-002'], $crawler->filter('.alignment-rule .code-chip')->each(static fn ($node): string => $node->text()));

        // Global workspace alignment view works and supports project filtering.
        $crawler = $this->client->request('GET', '/alignment?project=demo&filter=to_clarify');
        self::assertResponseIsSuccessful();
        self::assertSame(['RG-003', 'RG-002'], $crawler->filter('.alignment-rule .code-chip')->each(static fn ($node): string => $node->text()));
    }
}
