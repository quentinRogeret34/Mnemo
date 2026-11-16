<?php

namespace App\Tests\Rule;

use App\Document\DocumentStorage;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use App\Graph\KnowledgeReader;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ShadowAreaDetectorTest extends WebTestCase
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
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->rule = new Node($this->project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'validated']);
        $this->entityManager->persist($this->rule);
        $document = self::getContainer()->get(DocumentStorage::class)->storeContent(null, "# Remises\n\nLes clients fidèles ont droit à une remise.", 'Remises', 'md');
        $this->entityManager->persist(new Edge($document, $this->rule, 'defines', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->flush();
    }

    public function testAnalysisIsShownSortedByGravityAndCanBeResolved(): void
    {
        $this->scriptLlm(static fn (): array => ['areas' => [
            ['category' => 'ambiguous_term', 'gap' => '« client fidèle » n\'est pas défini.', 'question' => 'À partir de combien de commandes un client est-il fidèle ?', 'severity' => 'major'],
            ['category' => 'invented_category', 'gap' => 'Hors liste.', 'question' => '?', 'severity' => 'minor'],
            ['category' => 'missing_edge_case', 'gap' => 'Remise de 10 % exactement.', 'question' => 'Une remise de 10 % est-elle autorisée ?', 'severity' => 'blocker'],
        ]]);

        $crawler = $this->client->request('GET', '/projects/demo/rules/RG-042');
        self::assertSelectorTextContains('section[aria-labelledby="shadow-title"] .block__empty', 'Jamais analysée');
        $this->client->submit($crawler->selectButton('Analyser')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.flash', '2 zones d\'ombre trouvées.');
        self::assertStringContainsString('Les clients fidèles ont droit à une remise.', $this->llmCalls[0]['user']);
        self::assertStringContainsString('missing_acceptance_criterion', $this->llmCalls[0]['system']);
        self::assertSame(['Cas limite manquant', 'Terme ambigu'], $crawler->filter('.shadow-area .code-chip')->each(static fn ($node): string => $node->text()));
        self::assertSame(['Bloquant', 'Majeur'], $crawler->filter('.shadow-area__severity')->each(static fn ($node): string => $node->text()));

        $this->client->submit($crawler->filter('.shadow-area')->first()->selectButton('Résolue')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame(['Terme ambigu'], $crawler->filter('.shadow-area .code-chip')->each(static fn ($node): string => $node->text()));

        self::assertSame(1, self::getContainer()->get(KnowledgeReader::class)->rules($this->project)[0]['shadow_areas']);
        $crawler = $this->client->request('GET', '/projects/demo/rules');
        self::assertStringContainsString('1 zone d\'ombre', $crawler->filter('.row__meta')->text());
    }
}
