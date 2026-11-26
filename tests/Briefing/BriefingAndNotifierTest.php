<?php

namespace App\Tests\Briefing;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\WebhookFormat;
use App\Memory\MemoryStore;
use App\Notification\WorkspaceNotifier;
use App\Rule\ShadowAreaDetector;
use App\Security\SecretBox;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BriefingAndNotifierTest extends WebTestCase
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
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge RESTART IDENTITY CASCADE');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
    }

    public function testBriefingPageDisplaysCollectedActivityAndGeneratesAiSummary(): void
    {
        $file = new Node($this->project, NodeType::CodeFile, 'file:src/Billing/Invoice.php', 'Invoice.php', 'src/Billing/Invoice.php');
        $rule = new Node($this->project, NodeType::BusinessRule, 'RG-010', 'Relance facture J+30', null, null, [
            'statement' => 'Une facture impayée depuis 30 jours déclenche une relance.',
            'status' => 'validated',
            'shadow_areas' => [[
                'category' => 'missing_edge_case',
                'gap' => 'Jours ouvrés ou calendaires.',
                'question' => 'Les 30 jours sont-ils calendaires ou ouvrés ?',
                'severity' => 'blocker',
            ]],
        ]);
        $commit = new Node($this->project, NodeType::Commit, 'abc1234567890', 'feat: add invoice reminder schedule', null, null, ['author' => 'Alice']);
        $ticket = new Node($this->project, NodeType::Ticket, '#42', 'Relance automatique factures', null, null, ['kind' => 'pull_request', 'state' => 'merged', 'author' => 'Alice']);
        $this->entityManager->persist($file);
        $this->entityManager->persist($rule);
        $this->entityManager->persist($commit);
        $this->entityManager->persist($ticket);
        $this->entityManager->persist(new Edge($rule, $file, 'implemented_by', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->persist(new Edge($commit, $file, 'touches', Confidence::Extracted, EdgeOrigin::Git));

        $decision = self::getContainer()->get(MemoryStore::class)->add('Les relances partent uniquement les jours ouvrés.', MemoryType::Decision, 'shadow_answer');
        $decision->addProject($this->project);
        $this->entityManager->flush();

        $this->scriptLlm(static fn (): array => [
            'summary' => "**Ce qui a changé pour le métier**\n- La relance automatique J+30 est en place.\n- Il reste à trancher le décompte en jours calendaires ou ouvrés.",
        ]);

        $crawler = $this->client->request('GET', '/projects/demo/briefing?period=7d');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'RG-010');
        self::assertSelectorTextContains('body', 'Relance facture J+30');
        self::assertSelectorTextContains('body', 'Les relances partent uniquement les jours ouvrés.');
        self::assertSelectorTextContains('.briefing-health', 'Bloqué');
        self::assertSelectorExists('.briefing-blockers');
        self::assertSelectorTextContains('.briefing-blockers', 'Les 30 jours sont-ils calendaires ou ouvrés ?');
        self::assertSelectorTextContains('.briefing-export__code', '# Briefing métier · Demo project');

        $this->client->submit($crawler->selectButton('Générer le résumé IA')->form());
        self::assertResponseRedirects('/projects/demo/briefing?period=7d');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Synthèse métier générée');
        self::assertSelectorTextContains('.briefing-narrative', 'La relance automatique J+30 est en place.');
    }

    public function testBriefingPageHidesBlockersAndShowsAlignedHealthWhenProjectIsClean(): void
    {
        $file = new Node($this->project, NodeType::CodeFile, 'file:src/Billing/Tax.php', 'Tax.php', 'src/Billing/Tax.php');
        $rule = new Node($this->project, NodeType::BusinessRule, 'RG-020', 'TVA standard', null, null, [
            'statement' => 'Le taux de TVA standard est de 20 %.',
            'status' => 'validated',
            'resolved_shadow_areas' => [[
                'question' => 'La TVA s applique-t-elle aux frais de port ?',
                'answer' => 'Oui, au taux standard de 20 %.',
                'resolved_by' => 'Claire',
                'resolved_at' => (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM),
            ]],
        ]);
        $this->entityManager->persist($file);
        $this->entityManager->persist($rule);
        $this->entityManager->persist(new Edge($rule, $file, 'implemented_by', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->flush();

        $this->client->request('GET', '/projects/demo/briefing?period=7d');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.briefing-blockers');
        self::assertSelectorTextContains('.briefing-health', 'Aligné');
        self::assertSelectorExists('.briefing-all-clear');
        self::assertSelectorTextContains('.briefing-timeline', 'Oui, au taux standard de 20 %.');
    }

    public function testProjectSettingsRejectsUnsafeWebhookAndEncryptsValidHttpsWebhook(): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer les réglages')->form();
        $form['project[webhookUrl]'] = 'http://127.0.0.1/hook';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#notifications', 'HTTPS');

        $form = $this->client->getCrawler()->selectButton('Enregistrer les réglages')->form();
        $form['project[webhookFormat]'] = WebhookFormat::Slack->value;
        $form['project[webhookUrl]'] = 'https://hooks.slack.com/services/T000/B000/SECRET';
        $this->client->submit($form);
        self::assertResponseRedirects('/projects/demo/settings');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Project::class, $this->project->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasWebhook());
        self::assertNotSame('https://hooks.slack.com/services/T000/B000/SECRET', $reloaded->getWebhookUrlEncrypted());
        self::assertSame(
            'https://hooks.slack.com/services/T000/B000/SECRET',
            self::getContainer()->get(SecretBox::class)->decrypt((string) $reloaded->getWebhookUrlEncrypted()),
        );
    }

    public function testNotifierFormatsPayloadsAndTriggersOnBlockingShadowAreas(): void
    {
        /** @var \ArrayObject<int, array{method: string, url: string, body: mixed}> $captured */
        $captured = new \ArrayObject();
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($captured): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $secretBox = self::getContainer()->get(SecretBox::class);
        $notifier = new WorkspaceNotifier(
            $httpClient,
            $secretBox,
            self::getContainer()->get(UrlGeneratorInterface::class),
            new NullLogger(),
        );

        $this->project->setWebhookUrlEncrypted($secretBox->encrypt('https://93.184.216.34/webhook'));
        $this->project->setWebhookFormat(WebhookFormat::Slack);
        $this->project->setWebhookEvents([WorkspaceNotifier::EVENT_SHADOW_BLOCKER, WorkspaceNotifier::EVENT_PR_CONTRADICTION, WorkspaceNotifier::EVENT_BRIEFING]);
        $rule = new Node($this->project, NodeType::BusinessRule, 'RG-099', 'Seuil validation', null, null, ['statement' => 'Validation requise au-delà de 500 €.', 'status' => 'validated']);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        // Inject mock notifier into container for ShadowAreaDetector and BriefingController.
        self::getContainer()->set(WorkspaceNotifier::class, $notifier);

        $this->scriptLlm(static fn (): array => ['areas' => [[
            'category' => 'missing_edge_case',
            'gap' => 'HT ou TTC non précisé.',
            'question' => 'Le seuil de 500 € est-il HT ou TTC ?',
            'severity' => 'blocker',
        ]]]);

        self::getContainer()->get(ShadowAreaDetector::class)->analyze($rule);
        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]['method']);
        self::assertStringContainsString('RG-099', (string) ($captured[0]['body']['text'] ?? ''));

        // Switch format to Discord and send briefing via controller.
        $this->project->setWebhookFormat(WebhookFormat::Discord);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/projects/demo/briefing');
        $this->client->submit($crawler->selectButton('Envoyer sur Discord')->form());
        self::assertResponseRedirects('/projects/demo/briefing?period=7d');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Briefing métier envoyé');

        self::assertCount(2, $captured);
        self::assertStringContainsString('Briefing métier', (string) ($captured[1]['body']['content'] ?? ''));
    }
}
