<?php

namespace App\Tests\Rule;

use App\Document\DocumentStorage;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Graph\KnowledgeReader;
use App\Rule\ShadowAreaDetector;
use App\Tests\LlmTestTrait;
use App\Twig\Components\RuleEditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ShadowAreaDetectorTest extends WebTestCase
{
    use InteractsWithLiveComponents;
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

    public function testBusinessQuestionsInboxCapturesAnswerAsDecisionAndEnrichesStatement(): void
    {
        $this->scriptLlm(static fn (): array => ['areas' => [
            ['category' => 'ambiguous_term', 'gap' => '« client fidèle » non défini.', 'question' => 'Combien de commandes pour être fidèle ?', 'severity' => 'major'],
            ['category' => 'missing_edge_case', 'gap' => 'Seuil exact de 10 %.', 'question' => 'Une remise de 10 % pile passe-t-elle ?', 'severity' => 'blocker'],
        ]]);

        self::getContainer()->get(ShadowAreaDetector::class)->analyze($this->rule);

        $crawler = $this->client->request('GET', '/questions?severity=blocker');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.shadow-area'));
        self::assertSelectorTextContains('.shadow-area__question', 'Une remise de 10 % pile passe-t-elle ?');

        $form = $crawler->filter('.shadow-area')->first()->selectButton('Enregistrer la réponse')->form();
        $form['answer'] = 'Oui, 10 % inclus est autorisé ; au-delà une approbation manager est requise.';
        $form['save_decision']->tick();
        $form['append_statement']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/questions');
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.flash', 'décision #');
        self::assertCount(1, $crawler->filter('.shadow-area'));

        $this->entityManager->clear();
        $reloadedRule = $this->entityManager->find(Node::class, $this->rule->getId());
        self::assertNotNull($reloadedRule);
        self::assertStringContainsString('Oui, 10 % inclus est autorisé', (string) ($reloadedRule->getMetadata()['statement'] ?? ''));
        self::assertCount(1, $reloadedRule->getMetadata()['resolved_shadow_areas'] ?? []);
        self::assertSame('Oui, 10 % inclus est autorisé ; au-delà une approbation manager est requise.', $reloadedRule->getMetadata()['resolved_shadow_areas'][0]['answer']);

        $memories = self::getContainer()->get(KnowledgeReader::class)->memoriesAbout($reloadedRule->getId());
        self::assertCount(1, $memories);
        self::assertSame(MemoryType::Decision->value, $memories[0]['type']);
        self::assertStringContainsString('RG-042', $memories[0]['text']);

        $this->client->request('GET', '/projects/demo/rules/RG-042');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.shadow-resolved', '1 réponse métier archivée');
        self::assertSelectorTextContains('.shadow-resolved__answer', 'Oui, 10 % inclus est autorisé');

        $this->client->request('GET', '/projects/demo/questions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.shadow-area__question', 'Combien de commandes pour être fidèle ?');
    }

    public function testCreateRuleAndEditViaRuleEditor(): void
    {
        $codeFile = new Node($this->project, NodeType::CodeFile, 'file:src/Pricing/DiscountCalculator.php', 'DiscountCalculator.php', 'src/Pricing/DiscountCalculator.php');
        $this->entityManager->persist($codeFile);
        $extraDoc = self::getContainer()->get(DocumentStorage::class)->storeContent(null, "# CGV\n\nConditions générales.", 'CGV', 'md');
        $extraDoc->addProject($this->project);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/projects/demo/rules');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#rule-new form')->form([
            'title' => 'Validation commande > 500 €',
            'statement' => 'Toute commande dépassant 500 € HT exige une validation.',
            'status' => 'validated',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/projects/demo/rules/RG-043');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Règle RG-043 créée.');

        $created = $this->entityManager->getRepository(Node::class)->findOneBy([
            'project' => $this->project,
            'type' => NodeType::BusinessRule,
            'externalId' => 'RG-043',
        ]);
        self::assertNotNull($created);

        $component = $this->createLiveComponent(RuleEditor::class, ['rule' => $created], $this->client);
        $component->set('title', 'Validation commande > 500 € HT');
        $component->set('statement', 'Toute commande supérieure à 500 € HT demande une validation manager.');
        $component->call('save');
        self::assertStringContainsString('Règle enregistrée.', $component->render()->toString());

        $component->call('linkCode', ['nodeId' => $codeFile->getId()]);
        self::assertStringContainsString('DiscountCalculator.php', $component->render()->toString());

        $component->call('linkDocument', ['nodeId' => $extraDoc->getId()]);
        self::assertStringContainsString('CGV', $component->render()->toString());

        /** @var RuleEditor $editor */
        $editor = $component->component();
        $impls = $editor->getImplementations();
        $sources = $editor->getSources();
        self::assertCount(1, $impls);
        self::assertCount(1, $sources);

        $component->call('unlinkCode', ['edgeId' => $impls[0]['edge_id']]);
        $component->call('unlinkDocument', ['edgeId' => $sources[0]['edge_id']]);
        self::assertCount(0, $editor->getImplementations());
        self::assertCount(0, $editor->getSources());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Node::class, $created->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Validation commande > 500 € HT', $reloaded->getLabel());
        self::assertCount(2, $reloaded->getMetadata()['history'] ?? []);
        self::assertSame('UPDATE', $reloaded->getMetadata()['history'][1]['event'] ?? null);
    }
}
