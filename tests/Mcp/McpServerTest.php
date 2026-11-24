<?php

namespace App\Tests\Mcp;

use App\Entity\ApiKey;
use App\Entity\Edge;
use App\Entity\Folder;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use App\Enum\UserStatus;
use App\Ingest\GraphifyImporter;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class McpServerTest extends WebTestCase
{
    use LlmTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $sessionId;
    private int $requestId = 0;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, api_key RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($project, __DIR__.'/../Fixtures/graphify/graph-v1.json');

        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/domain/catalog.ts']);
        $rule = new Node($project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'validated']);
        $this->entityManager->persist($rule);
        $this->entityManager->persist(new Edge($rule, $file, 'implemented_by', Confidence::Verified, EdgeOrigin::Human));
        $this->entityManager->flush();

        $this->client->request('POST', '/mcp', server: $this->headers(), content: json_encode(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'phpunit', 'version' => '1']]]));
        self::assertResponseIsSuccessful();
        $this->sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $this->client->request('POST', '/mcp', server: $this->headers(), content: json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function testExposesOnlyTheApplicationTools(): void
    {
        $tools = $this->rpc('tools/list')['tools'];

        self::assertEqualsCanonicalizing(
            ['list_projects', 'search', 'file_context', 'get_document', 'add_memory', 'import_document', 'learn', 'memory_block', 'start_session', 'log_session_event', 'end_session', 'list_sessions'],
            array_column($tools, 'name'),
        );
    }

    public function testAddMemoryLinksAndDoesNotDuplicate(): void
    {
        $arguments = ['text' => 'Les remises passent par catalog.ts.', 'type' => 'convention', 'projects' => ['demo'], 'files' => ['src/domain/catalog.ts', 'src/unknown.ts'], 'rules' => ['RG-042'], 'agent' => 'claude-code'];

        $first = $this->tool('add_memory', $arguments);
        self::assertSame('ADD', $first['event']);
        self::assertSame(['src/unknown.ts'], $first['not_found']);

        $second = $this->tool('add_memory', ['text' => 'les remises passent par CATALOG.ts.', 'projects' => ['demo']]);
        self::assertSame('NONE', $second['event']);
        self::assertSame($first['id'], $second['id']);

        $context = $this->tool('file_context', ['project' => 'demo', 'path' => 'src/domain/catalog.ts']);
        self::assertSame(['Les remises passent par catalog.ts.'], array_column($context['memories'], 'text'));
        self::assertSame(['RG-042'], array_column($context['business_rules'], 'code'));
        self::assertSame(['src/main.ts'], $context['used_by']);

        $memory = $this->entityManager->find(Node::class, $first['id']);
        self::assertEquals(['source' => 'mcp', 'agent' => 'claude-code'], array_intersect_key($memory->getMetadata(), ['source' => 1, 'agent' => 1]));
        self::assertSame(2, $this->entityManager->getRepository(Edge::class)->count(['source' => $memory, 'relation' => 'about']));
    }

    public function testToolCallsAreRecordedAndShownOnTheDashboard(): void
    {
        $this->entityManager->getConnection()->executeStatement('TRUNCATE mcp_call RESTART IDENTITY');
        $this->tool('add_memory', ['text' => 'Les remises passent par catalog.ts.', 'projects' => ['demo'], 'files' => ['src/domain/catalog.ts']]);
        $this->tool('search', ['query' => 'remises', 'project' => 'demo', 'types' => ['memory', 'document']]);
        $this->tool('file_context', ['project' => 'demo', 'path' => 'src/domain/catalog.ts']);

        $calls = $this->entityManager->getConnection()->fetchAllAssociative('SELECT tool, project, query, search_types, results, memories, failed, client FROM mcp_call ORDER BY id');
        self::assertSame(['add_memory', 'search', 'file_context'], array_column($calls, 'tool'));
        self::assertSame(['tool' => 'search', 'project' => 'demo', 'query' => 'remises', 'search_types' => '["memory","document"]', 'results' => 1, 'memories' => 1, 'failed' => false, 'client' => 'phpunit'], $calls[1]);
        self::assertSame(1, $calls[2]['memories']);

        $crawler = $this->client->request('GET', '/dashboard?project=demo&period=24h');
        self::assertResponseIsSuccessful();
        self::assertSame(['1', '2', '3', '0'], $crawler->filter('.kpi__value')->each(static fn ($value): string => $value->text()));
        self::assertSame(['Documents 1 50,0 %', 'Mémoires 1 50,0 %'], $crawler->filter('section[aria-labelledby="breakdown-search"] .bars__row')->each(static fn ($row): string => $row->text()));
        self::assertCount(3, $crawler->filter('section[aria-labelledby="recent-title"] tbody tr'));
        self::assertSame(3, array_sum(array_column(json_decode($crawler->filter('[data-chart-series-value]')->first()->attr('data-chart-series-value'), true)[0]['points'], 'value')));
    }

    public function testAKeyLimitedToAProjectCannotReachTheOthers(): void
    {
        $other = new Project('other', 'Other project', '/repos/other');
        [$key, $this->token] = ApiKey::generate('laptop');
        $key->addProject($other);
        $this->entityManager->persist($other);
        $this->entityManager->persist($key);
        $this->entityManager->flush();

        self::assertSame(['other'], array_column($this->tool('list_projects', []), 'slug'));
        foreach ([['search', ['query' => 'catalog', 'project' => 'demo']], ['search', ['query' => 'catalog']], ['add_memory', ['text' => 'Sans projet.']], ['file_context', ['project' => 'demo', 'path' => 'src/domain/catalog.ts']]] as [$tool, $arguments]) {
            self::assertTrue($this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments])['isError'], $tool);
        }
        self::assertSame('ADD', $this->tool('add_memory', ['text' => 'Dans le périmètre.', 'projects' => ['other']])['event']);
        self::assertSame($key->getId(), $this->entityManager->getConnection()->fetchOne('SELECT api_key_id FROM mcp_call ORDER BY id DESC LIMIT 1'));
    }

    public function testAMemoryOfAProjectOutOfReachIsNotTakenOver(): void
    {
        $other = new Project('other', 'Other project', '/repos/other');
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $hidden = $this->tool('add_memory', ['text' => 'Le tarif B2B reste confidentiel.', 'projects' => ['other']]);

        [$key, $this->token] = ApiKey::generate('laptop');
        $key->addProject($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo']));
        $this->entityManager->persist($key);
        $this->entityManager->flush();

        $added = $this->tool('add_memory', ['text' => 'Le tarif B2B reste confidentiel.', 'projects' => ['demo']]);
        self::assertSame('ADD', $added['event']);
        self::assertNotSame($hidden['id'], $added['id']);
        self::assertSame(['other'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT p.slug FROM node_project np JOIN project p ON p.id = np.project_id WHERE np.node_id = ?', [$hidden['id']]));
    }

    public function testAPrivateMemoryIsOnlyRecalledForItsOwner(): void
    {
        $this->entityManager->getConnection()->executeStatement('TRUNCATE app_user, user_group RESTART IDENTITY CASCADE');
        $tokens = [];
        foreach (['alice', 'bob'] as $name) {
            $user = new User($name.'@example.com', ucfirst($name), 'github', $name);
            $user->setStatus(UserStatus::Active);
            $user->setSuperAdmin(true);
            [$key, $tokens[$name]] = ApiKey::generate($name);
            $key->setOwner($user);
            $this->entityManager->persist($user);
            $this->entityManager->persist($key);
        }
        $this->entityManager->flush();
        $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'bob@example.com'])->setSuperAdmin(false);
        $group = new UserGroup('Tous');
        $group->setUsers($this->entityManager->getRepository(User::class)->findAll());
        $group->setProjects($this->entityManager->getRepository(Project::class)->findAll());
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        $this->token = $tokens['alice'];
        $this->tool('add_memory', ['text' => 'Alice veut des réponses courtes.', 'type' => 'preference', 'projects' => ['demo'], 'visibility' => 'private']);
        self::assertCount(1, $this->tool('search', ['query' => 'réponses courtes', 'project' => 'demo', 'types' => ['memory']])['results']);

        $this->token = $tokens['bob'];
        self::assertCount(0, $this->tool('search', ['query' => 'réponses courtes', 'project' => 'demo', 'types' => ['memory']])['results']);
        self::assertSame('ADD', $this->tool('add_memory', ['text' => 'Alice veut des réponses courtes.', 'projects' => ['demo']])['event']);
        self::assertTrue($this->rpc('tools/call', ['name' => 'add_memory', 'arguments' => ['text' => 'Pour un groupe inconnu.', 'projects' => ['demo'], 'visibility' => 'group', 'group' => 'Inconnu']])['isError']);
    }

    public function testImportDocumentCreatesFoldersAndIsSearchable(): void
    {
        $imported = $this->tool('import_document', ['title' => 'Grille tarifaire', 'content' => "# Ancien titre\n\nLa remise maximale est de **10 %**.", 'folder' => 'Spécifications / Facturation', 'projects' => ['demo']]);
        self::assertSame('Facturation', $imported['folder']);
        self::assertSame('Spécifications', $this->entityManager->getRepository(Folder::class)->findOneBy(['name' => 'Facturation'])->getParent()->getName());

        $again = $this->tool('import_document', ['title' => 'Annexe', 'content' => 'Texte.', 'folder' => 'Spécifications/Facturation']);
        self::assertSame(2, $this->entityManager->getRepository(Folder::class)->count());
        self::assertNotSame($imported['id'], $again['id']);

        $document = $this->tool('get_document', ['id' => $imported['id']]);
        self::assertSame('Grille tarifaire', $document['title']);
        self::assertSame(['demo'], $document['projects']);
        self::assertStringContainsString('La remise maximale est de **10 %**.', $document['content']);
        self::assertStringNotContainsString('Ancien titre', $document['content']);

        $results = $this->tool('search', ['query' => 'tarifaire', 'project' => 'demo'])['results'];
        self::assertSame([['document', 'Grille tarifaire']], array_map(static fn (array $result): array => [$result['type'], $result['label']], $results));
    }

    public function testLearnQueuesProposalsInsteadOfStoringMemories(): void
    {
        $this->client->disableReboot();
        $this->scriptLlm(static fn (): array => ['candidates' => [[
            'lesson' => 'Les remises passent toujours par catalog.ts.', 'evidence' => 'vu dans la session', 'type' => 'convention',
            'durability' => 8, 'reuse' => 8, 'project_fit' => 8, 'risk' => 6, 'files' => ['src/domain/catalog.ts'], 'rules' => ['RG-042'],
            'adr' => ['context' => '', 'decision' => '', 'alternatives' => '', 'consequences' => ''],
        ]]]);

        $result = $this->tool('learn', ['text' => 'transcript', 'kind' => 'conversation', 'label' => 'session 1', 'project' => 'demo']);

        self::assertSame(1, $result['proposed']);
        self::assertStringEndsWith('/proposals?project=demo', $result['proposals_url']);
        self::assertSame(0, $this->entityManager->getRepository(Node::class)->count(['type' => NodeType::Memory]));
        self::assertSame(['kind' => 'conversation', 'label' => 'session 1'], $this->entityManager->getRepository(Proposal::class)->findOneBy([])->getPayload()['source']);
    }

    public function testToolErrorsAreReportedToTheAgent(): void
    {
        $result = $this->rpc('tools/call', ['name' => 'file_context', 'arguments' => ['project' => 'nope', 'path' => 'x']]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Unknown project "nope"', $result['content'][0]['text']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');

        return json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $this->client->request('POST', '/mcp', server: $this->headers() + ['HTTP_MCP_SESSION_ID' => $this->sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'], content: json_encode(['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => $method, 'params' => (object) $params]));
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['result'];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'] + (null === $this->token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
    }
}
