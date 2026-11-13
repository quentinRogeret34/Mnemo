<?php

namespace App\Tests\Connector;

use App\Connector\CredentialResolver;
use App\Connector\McpClientFactory;
use App\Entity\Connector;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorTransport;
use App\Enum\NodeType;
use App\Forge\GitHubAccount;
use App\Message\SyncConnector;
use App\Security\OAuth\OAuthClient;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class ConnectorControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector RESTART IDENTITY CASCADE');
        $this->entityManager->persist(new Project('demo', 'Demo project', '/repos/demo'));
        $this->entityManager->flush();
    }

    public function testConfigureInspectAndSyncAConnector(): void
    {
        $crawler = $this->client->request('GET', '/connectors/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'connector[name]' => 'mem0',
            'connector[transport]' => 'stdio',
            'connector[endpoint]' => 'php tests/Fixtures/mcp/server.php',
            'connector[credentials]' => "FIXTURE_CHARTE: env:NOT_USED_BY_THIS_TOOL\n",
            'connector[mode]' => 'tool_memories',
            'connector[tool]' => 'get_memories',
            'connector[arguments]' => '{"user_id": "quentin"}',
            'connector[itemsPath]' => 'results',
            'connector[memoryType]' => 'preference',
            'connector[project]' => (string) $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo'])->getId(),
        ]));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.props', 'FIXTURE_CHARTE: env:NOT_USED_BY_THIS_TOOL');

        $connector = $this->entityManager->getRepository(Connector::class)->findOneBy(['name' => 'mem0']);
        self::assertSame(['user_id' => 'quentin'], $connector->getArguments());

        $crawler = $this->client->submit($crawler->selectButton('Tester la connexion')->form());
        self::assertSelectorTextContains('[role="alert"]', 'La variable d\'environnement "NOT_USED_BY_THIS_TOOL" n\'est pas autorisée.');

        $connector->setCredentials([]);
        $this->entityManager->flush();
        $crawler = $this->client->request('GET', '/connectors/'.$connector->getId());
        $crawler = $this->client->submit($crawler->selectButton('Tester la connexion')->form());
        self::assertSame(['get_memories', 'weekly_report'], $crawler->filter('section[aria-labelledby="inspect-title"] .code-chip')->slice(0, 2)->each(static fn ($chip): string => $chip->text()));

        $this->client->submit($crawler->selectButton('Synchroniser')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Import terminé : 2 créés, 0 mis à jour, 1 inchangés, 1 ignorés.');
        self::assertSame(2, $this->entityManager->getRepository(Node::class)->count(['type' => NodeType::Memory]));

        $this->client->request('GET', '/connectors');
        self::assertSelectorTextContains('.content .row', '2 créés');
    }

    public function testACodeGraphSyncIsLeftToTheWorker(): void
    {
        $connector = new Connector();
        $connector->setName('Forge');
        $connector->setMode(ConnectorMode::GitHub);
        $connector->setEndpoint('https://api.github.com');
        $connector->setArguments(['repository' => 'acme/shop', 'code_graph' => true]);
        $connector->setProject($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo']));
        $this->entityManager->persist($connector);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/connectors/'.$connector->getId());
        $this->client->submit($crawler->selectButton('Synchroniser')->form());
        self::assertResponseRedirects('/connectors/'.$connector->getId());
        self::assertEquals(
            [new SyncConnector($connector->getId(), 'manual')],
            array_map(static fn ($envelope): object => $envelope->getMessage(), self::getContainer()->get('messenger.transport.async')->getSent()),
        );
        self::assertNull($connector->getLastRunAt());
    }

    public function testAGitHubAccountIsConnectedByOAuthAndItsTokenStoredEncrypted(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(OAuthClient::class, new OAuthClient(new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse(['access_token' => 'gho_secret'])), 'client-id', 'client-secret', '', '', 'https://gitlab.com', '', ''));
        $connector = new Connector();
        $connector->setName('Forge');
        $connector->setMode(ConnectorMode::GitHub);
        $connector->setEndpoint('https://api.github.com');
        $connector->setCredentials(['Authorization' => 'Bearer env:NOT_ALLOWED']);
        $connector->setArguments(['repository' => 'acme/shop']);
        $connector->setProject($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo']));
        $this->entityManager->persist($connector);
        $this->entityManager->flush();
        $show = '/connectors/'.$connector->getId();
        // Each request works on its own copy of the connector.
        $stored = function () use ($connector): Connector {
            $this->entityManager->clear();

            return $this->entityManager->find(Connector::class, $connector->getId());
        };
        $authorize = function () use ($show): array {
            $this->client->submit($this->client->request('GET', $show)->selectButton('Connecter avec GitHub')->form());
            parse_str((string) parse_url($this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);

            return $query;
        };

        $query = $authorize();
        self::assertSame(['repo', 'http://localhost/oauth/github/check/connector'], [$query['scope'], $query['redirect_uri']]);
        $this->client->request('GET', '/oauth/github/check/connector?code=abc&state=forged');
        self::assertResponseRedirects('/connectors');
        self::assertNull($stored()->getOauthToken());

        $query = $authorize();
        $this->client->request('GET', '/oauth/github/check/connector?code=abc&state='.$query['state']);
        self::assertResponseRedirects($show);
        $connector = $stored();
        self::assertStringNotContainsString('gho_secret', (string) $connector->getOauthToken());
        self::assertSame('gho_secret', self::getContainer()->get(SecretBox::class)->decrypt((string) $connector->getOauthToken()));
        self::assertSame(['Authorization' => 'Bearer gho_secret'], self::getContainer()->get(CredentialResolver::class)->forgeHeaders($connector));

        $connector->setEndpoint('https://attacker.example.com');
        try {
            self::getContainer()->get(CredentialResolver::class)->forgeHeaders($connector);
            self::fail('The OAuth token must never leave for another host.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('NOT_ALLOWED', $exception->getMessage());
        }

        $crawler = $this->client->request('GET', $show);
        self::assertSelectorTextContains('.props', 'compte GitHub connecté');
        $this->client->submit($crawler->selectButton('Déconnecter GitHub')->form());
        self::assertNull($stored()->getOauthToken());
    }

    public function testTheRepositoriesOfAGitHubAccountAreListedAndConnected(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(OAuthClient::class, new OAuthClient(new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse(['access_token' => 'gho_secret'])), 'client-id', 'client-secret', '', '', 'https://gitlab.com', '', ''));
        $listed = [];
        self::getContainer()->set(GitHubAccount::class, new GitHubAccount(new MockHttpClient(static function (string $method, string $url, array $options) use (&$listed): JsonMockResponse {
            $listed[] = [$url, $options['normalized_headers']['authorization'][0]];

            return new JsonMockResponse([
                ['full_name' => 'acme/shop', 'private' => true, 'description' => 'Boutique'],
                ['full_name' => 'acme/blog', 'private' => false, 'description' => null],
                ['full_name' => 'acme/old', 'private' => false, 'description' => null],
            ]);
        })));
        $existing = new Connector();
        $existing->setName('acme/old');
        $existing->setMode(ConnectorMode::GitHub);
        $existing->setEndpoint('https://api.github.com');
        $existing->setArguments(['repository' => 'Acme/Old']);
        $existing->setProject($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo']));
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $this->client->request('GET', '/connectors');
        $crawler = $this->client->click($this->client->getCrawler()->selectLink('Dépôts GitHub')->link());
        $this->client->submit($crawler->selectButton('Connecter avec GitHub')->form());
        parse_str((string) parse_url($this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        self::assertSame(['repo', 'http://localhost/oauth/github/check/repositories'], [$query['scope'], $query['redirect_uri']]);

        $this->client->request('GET', '/oauth/github/check/repositories?code=abc&state=forged');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'La connexion à GitHub a expiré');
        self::assertSame([], $listed);

        $this->client->submit($crawler->selectButton('Connecter avec GitHub')->form());
        parse_str((string) parse_url($this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->client->request('GET', '/oauth/github/check/repositories?code=abc&state='.$query['state']);
        $crawler = $this->client->followRedirect();
        self::assertSame([['https://api.github.com/user/repos?per_page=100&page=1&sort=pushed&affiliation=owner%2Ccollaborator%2Corganization_member', 'Authorization: Bearer gho_secret']], $listed);
        self::assertSame(['acme/shop', 'acme/blog', 'acme/old'], $crawler->filter('input[name="repositories[]"]')->each(static fn ($input): string => $input->attr('value')));
        self::assertNotNull($crawler->filter('input[value="acme/old"]')->attr('disabled'));

        $form = $crawler->selectButton('Brancher les dépôts cochés')->form();
        $form['project']->select((string) $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo'])->getId());
        $form['repositories'][0]->tick();
        $form['repositories'][1]->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/connectors');
        $sent = array_map(static fn ($envelope): object => $envelope->getMessage(), self::getContainer()->get('messenger.transport.async')->getSent());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', '2 dépôt(s) branché(s) sur Demo project');

        $this->entityManager->clear();
        $connectors = $this->entityManager->getRepository(Connector::class)->findBy(['name' => ['acme/shop', 'acme/blog']], ['id' => 'ASC']);
        self::assertSame(['acme/shop', 'acme/blog'], array_map(static fn (Connector $connector): string => $connector->getArguments()['repository'], $connectors));
        foreach ($connectors as $connector) {
            self::assertSame(['repository' => $connector->getName(), 'code_graph' => true, 'learn_reviews' => false], $connector->getArguments());
            self::assertSame(['Authorization' => 'Bearer gho_secret'], self::getContainer()->get(CredentialResolver::class)->forgeHeaders($connector));
            self::assertSame('demo', $connector->getProject()->getSlug());
        }
        self::assertEquals(
            array_map(static fn (Connector $connector): SyncConnector => new SyncConnector($connector->getId(), 'manual'), $connectors),
            $sent,
        );

        $this->client->request('GET', '/connectors/github');
        self::assertSelectorExists('button:contains("Connecter avec GitHub")');
    }

    public function testInvalidArgumentsAreRejected(): void
    {
        $crawler = $this->client->request('GET', '/connectors/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'connector[name]' => 'GitHub',
            'connector[endpoint]' => 'https://api.example.com/mcp',
            'connector[mode]' => 'tool_document',
            'connector[tool]' => 'list_issues',
            'connector[arguments]' => '[1, 2]',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-stack', 'Arguments JSON invalides');
        self::assertSame(0, $this->entityManager->getRepository(Connector::class)->count());
    }

    public function testToolModesRequireATool(): void
    {
        $crawler = $this->client->request('GET', '/connectors/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'connector[name]' => 'GitHub',
            'connector[endpoint]' => 'https://api.example.com/mcp',
            'connector[mode]' => 'tool_document',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-stack', 'Indiquer l\'outil à appeler.');
    }

    public function testPresetPrefillsTheForm(): void
    {
        $crawler = $this->client->request('GET', '/connectors');
        self::assertSame(['DeepWiki', 'Context7', 'mem0', 'GitHub (code et historique)', 'GitLab (code et historique)', 'GitHub', 'Linear'], $crawler->filter('.preset__name')->each(static fn ($name): string => $name->text()));

        $crawler = $this->client->request('GET', '/connectors/new?preset=mem0');
        self::assertSelectorTextContains('.callout--info', 'Définir MEM0_API_KEY dans .env.local.');
        $this->client->submit($crawler->selectButton('Enregistrer')->form());
        self::assertResponseRedirects();

        $connector = $this->entityManager->getRepository(Connector::class)->findOneBy(['name' => 'mem0']);
        self::assertSame('https://mcp.mem0.ai/mcp/', $connector->getEndpoint());
        self::assertSame(['Authorization' => 'Token env:MEM0_API_KEY'], $connector->getCredentials());
        self::assertSame(['get_memories', 'results', 'memory'], [$connector->getTool(), $connector->getItemsPath(), $connector->getTextField()]);
        self::assertSame(['page' => 1, 'page_size' => 100], $connector->getArguments());

        $this->client->request('GET', '/connectors/new?preset=unknown');
        self::assertResponseStatusCodeSame(404);
    }

    public function testStdioCanBeDisabled(): void
    {
        $connector = new Connector();
        $connector->setTransport(ConnectorTransport::Stdio);
        $connector->setEndpoint('php tests/Fixtures/mcp/server.php');

        $this->expectExceptionMessage('Les connecteurs STDIO sont désactivés');
        (new McpClientFactory(false, new CredentialResolver([], new SecretBox('test')), '/app'))->connect($connector);
    }
}
