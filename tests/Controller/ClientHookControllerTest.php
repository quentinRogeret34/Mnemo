<?php

namespace App\Tests\Controller;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Ingest\GraphifyImporter;
use App\Memory\MemoryStore;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ClientHookControllerTest extends WebTestCase
{
    use LlmTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($project);
        $this->entityManager->persist(new Project('demo-sub', 'Nested project', '/repos/demo/packages/sub'));
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($project, __DIR__.'/../Fixtures/graphify/graph-v1.json');

        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/domain/catalog.ts']);
        $rule = new Node($project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'validated']);
        $this->entityManager->persist($rule);
        $this->entityManager->persist(new Edge($rule, $file, 'implemented_by', Confidence::Verified, EdgeOrigin::Human));
        $memory = self::getContainer()->get(MemoryStore::class)->add('Les remises passent par catalog.ts.', MemoryType::Convention, 'manual');
        $memory->addProject($project);
        $this->entityManager->flush();
        self::getContainer()->get(MemoryStore::class)->link($memory, $file);
        $this->entityManager->flush();
    }

    public function testSessionContextIsTheProjectMemoryWithoutMarkers(): void
    {
        $context = $this->get('/api/context?cwd=/repos/demo/src/domain');

        self::assertSame('demo', $context['project']);
        self::assertSame(1, $context['memories']);
        self::assertStringStartsWith('## Mémoire projet', $context['context']);
        self::assertStringContainsString('- Les remises passent par catalog.ts.', $context['context']);
        self::assertStringContainsString('`RG-042` Remise plafonnée', $context['context']);
        self::assertStringNotContainsString('knowledge_graph:start', $context['context']);

        self::assertSame('demo-sub', $this->get('/api/context?cwd=/repos/demo/packages/sub/lib')['project']);
        self::assertFalse($context['created']);
        self::assertSame(['project' => null, 'created' => false, 'memories' => 0, 'context' => ''], $this->get('/api/context'));
    }

    public function testAnUnknownProjectIsCreatedFromItsRepositoryElseItsDirectory(): void
    {
        $created = $this->get('/api/context?cwd=/home/dev/shop&repository=GitHub.com/Acme/Shop.git');
        self::assertSame(['project' => 'shop', 'created' => true, 'memories' => 0], array_intersect_key($created, ['project' => 1, 'created' => 1, 'memories' => 1]));
        $shop = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'shop']);
        self::assertSame(['github.com/acme/shop', '/home/dev/shop'], [$shop->getRepository(), $shop->getRepositoryPath()]);

        $again = $this->get('/api/context?cwd=/workspace&repository=github.com/acme/shop');
        self::assertSame(['shop', false], [$again['project'], $again['created']]);
        self::assertSame('acme-fork-shop', $this->get('/api/context?cwd=/workspace&repository=github.com/acme-fork/shop')['project']);

        self::assertSame('demo-other', $this->get('/api/context?cwd=/repos/Demo%20Other')['project']);
        self::assertNull($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo-other'])->getRepository());

        self::assertSame('', $this->get('/api/file-context?cwd=/repos/unseen&repository=github.com/acme/unseen&path=/repos/unseen/a.ts')['context']);
        self::assertSame(5, $this->entityManager->getRepository(Project::class)->count([]));
    }

    public function testAnArchivedProjectIsNeitherFoundNorCreatedAgain(): void
    {
        $projects = $this->entityManager->getRepository(Project::class);
        $demo = $projects->findOneBy(['slug' => 'demo']);
        $demo->setRepository('github.com/acme/demo');
        $demo->archive();
        $this->entityManager->flush();

        foreach (['cwd=/repos/demo/src/domain', 'cwd=/home/dev/shop&project=demo', 'cwd=/home/dev/shop&repository=github.com/acme/demo'] as $query) {
            self::assertSame(['project' => null, 'created' => false, 'memories' => 0, 'context' => ''], $this->get('/api/context?'.$query), $query);
        }
        self::assertSame(['project' => null, 'path' => null, 'context' => ''], $this->get('/api/file-context?cwd=/repos/demo&path=/repos/demo/src/domain/catalog.ts'));
        $this->client->jsonRequest('POST', '/api/learn', ['cwd' => '/repos/demo', 'text' => 'transcript', 'label' => 'x']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('demo-sub', $this->get('/api/context?cwd=/repos/demo/packages/sub/lib')['project']);
        self::assertSame(2, $projects->count([]));

        self::assertNull($this->get('/api/context?cwd=/home/dev/new&project=new')['project']);
        self::assertSame('acme-2-new', $this->get('/api/context?cwd=/home/dev/new&repository=github.com/acme-2/new')['project']);
    }

    public function testFileContextListsRulesAndMemoriesOrNothing(): void
    {
        $context = $this->get('/api/file-context?cwd=/repos/demo&path=/repos/demo/src/domain/catalog.ts');

        self::assertSame('src/domain/catalog.ts', $context['path']);
        self::assertStringContainsString('- Règle RG-042 Remise plafonnée (validée)', $context['context']);
        self::assertStringContainsString('- Mémoire (convention) : Les remises passent par catalog.ts.', $context['context']);

        self::assertSame('', $this->get('/api/file-context?cwd=/repos/demo&path=src/main.ts')['context']);
        self::assertSame('', $this->get('/api/file-context?cwd=/repos/demo&path=/etc/passwd')['context']);
    }

    public function testPromptContextListsTheMemoriesAndRulesCloseToThePrompt(): void
    {
        $this->client->jsonRequest('POST', '/api/prompt-context', ['cwd' => '/repos/demo', 'prompt' => 'Comment sont calculées les remises ?']);
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('demo', $context['project']);
        self::assertStringContainsString('- Mémoire : Les remises passent par catalog.ts.', $context['context']);
        self::assertStringContainsString('- Règle RG-042 Remise plafonnée : Une remise ne dépasse jamais 10 %.', $context['context']);

        $this->client->jsonRequest('POST', '/api/prompt-context', ['cwd' => '/repos/demo', 'prompt' => 'ok']);
        self::assertSame('', json_decode((string) $this->client->getResponse()->getContent(), true)['context']);
        $this->client->jsonRequest('POST', '/api/prompt-context', ['cwd' => '/elsewhere', 'prompt' => 'Comment sont calculées les remises ?']);
        self::assertSame(['project' => null, 'context' => ''], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testAClientOnAnotherMachineNamesItsProject(): void
    {
        self::assertSame('demo', $this->get('/api/context?cwd=/home/dev/shop&project=demo')['project']);
        self::assertNull($this->get('/api/context?cwd=/repos/demo&project=Not%20A%20Slug')['project']);

        $named = $this->get('/api/context?cwd=/home/dev/billing&project=billing&repository=github.com/acme/monolith');
        self::assertSame(['billing', true], [$named['project'], $named['created']]);
        self::assertSame('demo', $this->get('/api/context?cwd=/home/dev/shop&project=demo&repository=github.com/acme/demo')['project']);
        $this->entityManager->clear();
        self::assertSame('github.com/acme/demo', $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo'])->getRepository());

        $context = $this->get('/api/file-context?cwd=/home/dev/shop&project=demo&path=/home/dev/shop/src/domain/catalog.ts');
        self::assertSame('src/domain/catalog.ts', $context['path']);
        self::assertStringContainsString('- Règle RG-042 Remise plafonnée (validée)', $context['context']);

        $this->client->jsonRequest('POST', '/api/learn', ['text' => 'transcript', 'label' => 'x']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testLearnQueuesProposalsForTheProjectOfTheDirectory(): void
    {
        $this->scriptLlm(static fn (): array => ['candidates' => [[
            'lesson' => 'Le catalogue est chargé une seule fois au démarrage.', 'evidence' => 'vu', 'type' => 'fact',
            'durability' => 8, 'reuse' => 8, 'project_fit' => 8, 'risk' => 8, 'files' => [], 'rules' => [],
            'adr' => ['context' => '', 'decision' => '', 'alternatives' => '', 'consequences' => ''],
        ]]]);

        $this->client->jsonRequest('POST', '/api/learn', ['cwd' => '/repos/demo', 'text' => 'transcript', 'label' => 'claude-code abc']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, json_decode((string) $this->client->getResponse()->getContent(), true)['proposed']);
        self::assertSame('demo', $this->entityManager->getRepository(Proposal::class)->findOneBy([])->getProject()->getSlug());
    }

    public function testLearnRejectsAnInvalidPayloadAndCreatesAnUnknownProject(): void
    {
        $this->scriptLlm(static fn (): array => ['candidates' => []]);
        $this->client->jsonRequest('POST', '/api/learn', ['cwd' => '/repos/demo', 'text' => '', 'label' => 'x', 'kind' => 'chat']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager->getRepository(Proposal::class)->count([]));

        $this->client->jsonRequest('POST', '/api/learn', ['cwd' => '/elsewhere', 'repository' => 'gitlab.com/acme/tools/cli', 'text' => 'transcript', 'label' => 'x']);
        self::assertResponseIsSuccessful();
        self::assertSame('cli', json_decode((string) $this->client->getResponse()->getContent(), true)['project']);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
