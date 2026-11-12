<?php

namespace App\Tests\Connector;

use App\Connector\CredentialResolver;
use App\Connector\McpClientFactory;
use App\Entity\Connector;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\ConnectorTransport;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
        self::assertSame(['DeepWiki', 'Context7', 'mem0', 'GitHub (historique)', 'GitLab (historique)', 'GitHub', 'Linear'], $crawler->filter('.preset__name')->each(static fn ($name): string => $name->text()));

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
        (new McpClientFactory(false, new CredentialResolver([]), '/app'))->connect($connector);
    }
}
