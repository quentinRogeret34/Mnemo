<?php

namespace App\Tests\Forge;

use App\Connector\CredentialResolver;
use App\Entity\Connector;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\NodeType;
use App\Forge\ForgeClientFactory;
use App\Forge\ForgeImporter;
use App\Forge\RepositoryCheckout;
use App\Ingest\GraphifyImporter;
use App\Ingest\GraphifyRunner;
use App\Ingest\RepoContextImporter;
use App\Learn\Learner;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class ForgeImporterTest extends WebTestCase
{
    use LlmTestTrait;

    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;
    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector RESTART IDENTITY CASCADE');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->persist(new Node($this->project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'validated']));
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($this->project, __DIR__.'/../Fixtures/graphify/graph-v1.json');
    }

    /**
     * @param array<string, mixed> $responses path suffix => JSON payload
     */
    #[DataProvider('forges')]
    public function testHistoryIsLinkedToFilesRulesAndPeopleWithoutDuplicates(ConnectorMode $mode, string $endpoint, string $changeRequest, array $responses): void
    {
        $connector = new Connector();
        $connector->setName('Forge');
        $connector->setMode($mode);
        $connector->setEndpoint($endpoint);
        $connector->setArguments(['repository' => 'acme/shop']);
        $connector->setProject($this->project);
        $this->entityManager->persist($connector);
        $this->entityManager->flush();

        $http = new MockHttpClient(function (string $method, string $url) use ($responses): JsonMockResponse {
            $this->requested[] = $url;
            $path = parse_url($url, \PHP_URL_PATH);
            foreach ($responses as $suffix => $payload) {
                if (str_ends_with($path, $suffix)) {
                    return new JsonMockResponse($payload);
                }
            }

            return new JsonMockResponse(['message' => 'Not Found: '.$path], ['http_code' => 404]);
        });
        $importer = $this->importer($http, self::getContainer()->get(RepositoryCheckout::class), self::getContainer()->get(GraphifyRunner::class));
        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'error' => null];
        $importer->import($connector, $report);
        self::assertSame(4, $report['created']);

        $edges = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT s.external_id || ' ' || e.relation || ' ' || COALESCE(t.path, t.external_id) FROM edge e JOIN node s ON s.id = e.source_id JOIN node t ON t.id = e.target_id WHERE e.origin = 'api' ORDER BY 1",
        );
        self::assertSame([
            self::SHA_A.' authored_by alice',
            self::SHA_A.' mentions RG-042',
            self::SHA_A.' touches src/domain/catalog.ts',
            self::SHA_B.' authored_by bob',
            self::SHA_B.' touches src/main.ts',
            'issue-7 authored_by bob',
            $changeRequest.' authored_by alice',
            $changeRequest.' closes issue-7',
            $changeRequest.' includes '.self::SHA_A,
        ], $edges);
        self::assertSame(2, $this->entityManager->getRepository(Node::class)->count(['type' => NodeType::Person]));

        $again = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'error' => null];
        $this->requested = [];
        $importer->import($connector, $again);
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 4], \array_slice($again, 0, 3));
        self::assertCount(9, $this->entityManager->getConnection()->fetchFirstColumn("SELECT id FROM edge WHERE origin = 'api'"));
        self::assertStringContainsString('2026-11-02', urldecode(implode(' ', $this->requested)));

        $crawler = $this->client->request('GET', '/projects/demo/files/src/domain/catalog.ts');
        self::assertSame('Plafonne la remise (RG-042) '.$changeRequest, $crawler->filter('section[aria-labelledby="history-title"] .row__title')->text());
        $crawler = $this->client->request('GET', '/projects/demo/rules/RG-042');
        self::assertSame('aaaaaaa', $crawler->filter('section[aria-labelledby="mentions-title"] .code-chip')->text());
    }

    public function testCodeGraphImportsTheCloneOncePerHeadCommit(): void
    {
        $root = sys_get_temp_dir().'/graphe-clone-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($root.'/README.md', "# Shop\n\nLa boutique.");
        $filesystem->dumpFile($root.'/docs/guide.md', "# Guide\n\nInstaller puis lancer.");
        $filesystem->dumpFile($root.'/CLAUDE.md', "# Instructions\n\nRester simple.");
        $filesystem->symlink('/etc/hostname', $root.'/docs/secret.md');

        $connector = new Connector();
        $connector->setName('Forge');
        $connector->setMode(ConnectorMode::GitHub);
        $connector->setEndpoint('https://api.github.com');
        $connector->setArguments(['repository' => 'Acme/Shop', 'code_graph' => true]);
        $connector->setProject($this->project);
        $this->entityManager->persist($connector);
        $this->entityManager->flush();

        $checkout = new class(self::getContainer()->get(CredentialResolver::class), $root) extends RepositoryCheckout {
            public string $commit = 'c1';

            public function __construct(CredentialResolver $credentials, private readonly string $root)
            {
                parent::__construct($credentials, $root);
            }

            public function checkout(Connector $connector): array
            {
                return ['path' => $this->root, 'commit' => $this->commit];
            }
        };
        $graphify = new class extends GraphifyRunner {
            public int $calls = 0;

            public function extract(string $repository, string $output): string
            {
                ++$this->calls;

                return __DIR__.'/../Fixtures/graphify/graph-v2.json';
            }
        };
        $importer = $this->importer(new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse([])), $checkout, $graphify);
        $run = static function () use ($importer, $connector): array {
            $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'error' => null];
            $importer->import($connector, $report);

            return $report;
        };

        $report = $run();
        self::assertSame(['docs/secret.md : lien hors du dépôt'], $report['skipped']);
        self::assertGreaterThanOrEqual(3, $report['created']);
        $documents = $this->entityManager->getConnection()->fetchFirstColumn("SELECT label FROM node WHERE type = 'document'");
        sort($documents);
        self::assertSame(['CLAUDE.md', 'README.md', 'docs/guide.md'], $documents);
        self::assertSame('github.com/acme/shop', $this->project->getRepository());
        self::assertSame(['code_commit' => 'c1'], $connector->getCursor());

        $run();
        self::assertSame(1, $graphify->calls);
        $checkout->commit = 'c2';
        $run();
        self::assertSame(2, $graphify->calls);
        self::assertSame(['code_commit' => 'c2'], $connector->getCursor());
        $filesystem->remove($root);
    }

    public function testReviewsAreLearntFromOnceWhenAPullRequestSeenOpenGetsMerged(): void
    {
        $this->scriptLlm(static fn (): array => ['candidates' => []]);
        $connector = new Connector();
        $connector->setName('Forge');
        $connector->setMode(ConnectorMode::GitHub);
        $connector->setEndpoint('https://api.github.com');
        $connector->setArguments(['repository' => 'acme/shop', 'learn_reviews' => true]);
        $connector->setProject($this->project);
        $this->entityManager->persist($connector);
        $this->entityManager->flush();

        $mergedAt = null;
        $importer = $this->importer(new MockHttpClient(static function (string $method, string $url) use (&$mergedAt): JsonMockResponse {
            $path = parse_url($url, \PHP_URL_PATH);

            return new JsonMockResponse(match (true) {
                str_ends_with($path, '/pulls') => [['number' => 3, 'title' => 'Remise plafonnée', 'body' => '', 'state' => null === $mergedAt ? 'open' : 'closed', 'merged_at' => $mergedAt, 'user' => ['login' => 'alice'], 'updated_at' => $mergedAt ?? '2026-11-02T12:00:00Z', 'html_url' => 'https://github.com/acme/shop/pull/3']],
                str_ends_with($path, '/pulls/3/reviews') => [['body' => 'Le plafond doit rester configurable.']],
                default => [],
            });
        }), self::getContainer()->get(RepositoryCheckout::class), self::getContainer()->get(GraphifyRunner::class));
        $run = static function () use ($importer, $connector): void {
            $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'error' => null];
            $importer->import($connector, $report);
        };

        $run();
        self::assertCount(0, $this->llmCalls);

        $mergedAt = '2026-11-03T12:00:00Z';
        $run();
        self::assertCount(1, $this->llmCalls);
        self::assertStringContainsString('Le plafond doit rester configurable.', $this->llmCalls[0]['user']);

        $run();
        self::assertCount(1, $this->llmCalls);
    }

    private function importer(MockHttpClient $http, RepositoryCheckout $checkout, GraphifyRunner $graphify): ForgeImporter
    {
        return new ForgeImporter(
            new ForgeClientFactory($http, self::getContainer()->get(CredentialResolver::class)),
            $this->entityManager,
            self::getContainer()->get(Learner::class),
            $checkout,
            $graphify,
            self::getContainer()->get(GraphifyImporter::class),
            self::getContainer()->get(RepoContextImporter::class),
        );
    }

    /**
     * @return iterable<string, array{ConnectorMode, string, string, array<string, mixed>}>
     */
    public static function forges(): iterable
    {
        yield 'github' => [ConnectorMode::GitHub, 'https://api.github.com', 'pr-3', [
            '/commits/'.self::SHA_A => ['files' => [['filename' => 'src/domain/catalog.ts'], ['filename' => 'README.md']]],
            '/commits/'.self::SHA_B => ['files' => [['filename' => 'src/main.ts']]],
            '/pulls/3/commits' => [['sha' => self::SHA_A]],
            '/commits' => [
                ['sha' => self::SHA_A, 'html_url' => 'https://github.com/acme/shop/commit/a', 'author' => ['login' => 'alice'], 'commit' => ['message' => "Plafonne la remise (RG-042)\n\nDétail.", 'author' => ['name' => 'Alice', 'date' => '2026-11-02T10:00:00Z']]],
                ['sha' => self::SHA_B, 'html_url' => 'https://github.com/acme/shop/commit/b', 'author' => ['login' => 'bob'], 'commit' => ['message' => 'Initialise le projet', 'author' => ['name' => 'Bob', 'date' => '2026-11-01T10:00:00Z']]],
            ],
            '/pulls' => [['number' => 3, 'title' => 'Remise plafonnée', 'body' => 'Fixes #7', 'state' => 'closed', 'merged_at' => '2026-11-02T12:00:00Z', 'user' => ['login' => 'alice'], 'updated_at' => '2026-11-02T12:00:00Z', 'html_url' => 'https://github.com/acme/shop/pull/3']],
            '/issues' => [
                ['number' => 7, 'title' => 'Remise trop forte', 'body' => null, 'state' => 'closed', 'user' => ['login' => 'bob'], 'updated_at' => '2026-11-02T12:00:00Z', 'html_url' => 'https://github.com/acme/shop/issues/7'],
                ['number' => 3, 'title' => 'Remise plafonnée', 'pull_request' => [], 'state' => 'closed', 'user' => ['login' => 'alice'], 'updated_at' => '2026-11-02T12:00:00Z', 'html_url' => 'https://github.com/acme/shop/pull/3'],
            ],
        ]];

        yield 'gitlab' => [ConnectorMode::GitLab, 'https://gitlab.com/api/v4', 'mr-3', [
            '/repository/commits/'.self::SHA_A.'/diff' => [['new_path' => 'src/domain/catalog.ts'], ['new_path' => 'README.md']],
            '/repository/commits/'.self::SHA_B.'/diff' => [['new_path' => 'src/main.ts']],
            '/merge_requests/3/commits' => [['id' => self::SHA_A]],
            '/repository/commits' => [
                ['id' => self::SHA_A, 'web_url' => 'https://gitlab.com/acme/shop/-/commit/a', 'author_name' => 'alice', 'message' => "Plafonne la remise (RG-042)\n\nDétail.", 'committed_date' => '2026-11-02T10:00:00Z'],
                ['id' => self::SHA_B, 'web_url' => 'https://gitlab.com/acme/shop/-/commit/b', 'author_name' => 'bob', 'message' => 'Initialise le projet', 'committed_date' => '2026-11-01T10:00:00Z'],
            ],
            '/merge_requests' => [['iid' => 3, 'title' => 'Remise plafonnée', 'description' => 'Closes #7', 'state' => 'merged', 'author' => ['username' => 'alice'], 'updated_at' => '2026-11-02T12:00:00Z', 'web_url' => 'https://gitlab.com/acme/shop/-/merge_requests/3']],
            '/issues' => [['iid' => 7, 'title' => 'Remise trop forte', 'description' => null, 'state' => 'closed', 'author' => ['username' => 'bob'], 'updated_at' => '2026-11-02T12:00:00Z', 'web_url' => 'https://gitlab.com/acme/shop/-/issues/7']],
        ]];
    }

    public function testForgeConnectorNeedsAProjectAndARepository(): void
    {
        $crawler = $this->client->request('GET', '/connectors/new?preset=github-api');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['connector[arguments]'] = '{"learn_reviews": false}';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Indiquer le dépôt', $this->client->getResponse()->getContent());
        self::assertStringContainsString('alimente un projet', $this->client->getResponse()->getContent());
    }
}
