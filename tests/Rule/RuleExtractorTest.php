<?php

namespace App\Tests\Rule;

use App\Document\DocumentStorage;
use App\Document\DocumentTextExtractor;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Graph\KnowledgeReader;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RuleExtractorTest extends WebTestCase
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
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, proposal RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->persist(new Node($this->project, NodeType::CodeFile, 'file:src/Pricing/DiscountPolicy.php', 'DiscountPolicy.php', 'src/Pricing/DiscountPolicy.php'));
        $this->entityManager->flush();
    }

    public function testExtractsTextFromDocxAndPdfFiles(): void
    {
        $storage = self::getContainer()->get(DocumentStorage::class);
        $extractor = new DocumentTextExtractor($storage);

        $docxBytes = $this->buildDocx([
            'Politique de facturation',
            'Toute facture impayée au-delà de 30 jours génère une relance automatique.',
        ]);
        $docx = $storage->storeContent(null, $docxBytes, 'Politique facturation', 'docx');
        $docx->addProject($this->project);

        $pdfBytes = $this->buildMinimalPdf('Une remise commerciale ne depasse jamais 10 pourcent.');
        $pdf = $storage->storeContent(null, $pdfBytes, 'Remises PDF', 'pdf');
        $pdf->addProject($this->project);
        $this->entityManager->flush();

        $docxText = $extractor->extract($docx);
        self::assertStringContainsString('Politique de facturation', $docxText);
        self::assertStringContainsString('relance automatique', $docxText);

        $pdfText = $extractor->extract($pdf);
        self::assertStringContainsString('remise commerciale ne depasse jamais 10 pourcent', $pdfText);

        $this->client->request('GET', '/documents/'.$docx->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.doc__body', 'relance automatique');
        self::assertSelectorTextContains('.doc-extract-form', 'Extraire les règles métier');
    }

    public function testExtractsRulesIntoProposalQueueAndAppliesEditedRuleWithLinks(): void
    {
        $storage = self::getContainer()->get(DocumentStorage::class);
        $docx = $storage->storeContent(null, $this->buildDocx([
            'Spécification remises',
            'La remise maximale sur un panier est plafonnée à 10 % sauf validation direction.',
            'Les commandes annulées sont remboursées sous 5 jours ouvrés.',
        ]), 'Spec Remises', 'docx');
        $docx->addProject($this->project);
        $this->entityManager->flush();

        $this->scriptLlm(static fn (): array => ['rules' => [
            [
                'title' => 'Plafond de remise panier',
                'statement' => 'Une remise panier ne dépasse jamais 10 % sans validation direction.',
                'excerpt' => 'La remise maximale sur un panier est plafonnée à 10 % sauf validation direction.',
            ],
            [
                'title' => 'Délai de remboursement',
                'statement' => 'Les commandes annulées sont remboursées sous 5 jours ouvrés.',
                'excerpt' => 'Les commandes annulées sont remboursées sous 5 jours ouvrés.',
            ],
        ]]);

        $crawler = $this->client->request('GET', '/documents/'.$docx->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('.doc-extract-form')->form());
        self::assertResponseRedirects('/proposals?project=demo&kind=rule_draft');
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.flash', '2 règles métier extraites');
        self::assertCount(2, $crawler->filter('.proposal-row'));
        self::assertStringContainsString('Spécification remises', $this->llmCalls[0]['user']);

        // Second extraction on the same document deduplicates against pending proposals.
        $crawlerDoc = $this->client->request('GET', '/documents/'.$docx->getId());
        $this->client->submit($crawlerDoc->filter('.doc-extract-form')->form());
        self::assertResponseRedirects('/documents/'.$docx->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Aucune nouvelle règle métier');

        // Apply the first RuleDraft proposal with human edits.
        $crawler = $this->client->request('GET', '/proposals?project=demo&kind=rule_draft');
        $firstForm = $crawler->filter('.proposal-drawer')->first()->selectButton('Valider et créer la règle')->form();
        $firstForm['title'] = 'Plafond de remise panier (10 %)';
        $firstForm['statement'] = 'Une remise panier ne dépasse jamais 10 % TTC sans validation direction.';
        $this->client->submit($firstForm);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash', 'Règle RG-001 validée et créée');

        $this->entityManager->clear();
        $rule = $this->entityManager->getRepository(Node::class)->findOneBy([
            'project' => $this->project,
            'type' => NodeType::BusinessRule,
            'externalId' => 'RG-001',
        ]);
        self::assertNotNull($rule);
        self::assertSame('Plafond de remise panier (10 %)', $rule->getLabel());
        self::assertSame('validated', $rule->getMetadata()['status'] ?? null);
        self::assertStringContainsString('10 % TTC', (string) ($rule->getMetadata()['statement'] ?? ''));

        $reader = self::getContainer()->get(KnowledgeReader::class);
        $sources = $reader->ruleSources($rule);
        self::assertCount(1, $sources);
        self::assertSame($docx->getId(), $sources[0]['id']);

        $applied = $this->entityManager->getRepository(Proposal::class)->findOneBy([
            'project' => $this->project,
            'kind' => ProposalKind::RuleDraft,
            'status' => ProposalStatus::Applied,
        ]);
        self::assertNotNull($applied);
        self::assertTrue((bool) ($applied->getPayload()['human_feedback']['edited'] ?? false));
        self::assertSame('RG-001', $applied->getPayload()['applied_rule_code'] ?? null);
    }

    /**
     * @param list<string> $paragraphs
     */
    private function buildDocx(array $paragraphs): string
    {
        $xmlParagraphs = implode('', array_map(
            static fn (string $text): string => '<w:p><w:r><w:t>'.htmlspecialchars($text, \ENT_XML1).'</w:t></w:r></w:p>',
            $paragraphs,
        ));
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .$xmlParagraphs
            .'</w:body></w:document>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
        self::assertIsString($tmp);
        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private function buildMinimalPdf(string $text): string
    {
        $stream = "BT\n/F1 12 Tf\n72 720 Td\n(".$text.") Tj\nET";
        $compressed = (string) gzcompress($stream);

        return "%PDF-1.4\n1 0 obj\n<< /Length ".\strlen($compressed)." /Filter /FlateDecode >>\nstream\n".$compressed."\nendstream\nendobj\n%%EOF";
    }
}
