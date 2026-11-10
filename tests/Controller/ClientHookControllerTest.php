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
        self::assertSame(['project' => null, 'memories' => 0, 'context' => ''],$this->get('/api/context?cwd=/repos/demo-other'));
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

    public function testLearnRejectsAnInvalidPayloadOrAnUnknownDirectory(): void
    {
        $this->client->jsonRequest('POST', '/api/learn', ['cwd' => '/repos/demo', 'text' => '', 'label' => 'x', 'kind' => 'chat']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/learn', ['cwd' => '/elsewhere', 'text' => 'transcript', 'label' => 'x']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager->getRepository(Proposal::class)->count([]));
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
