<?php

namespace App\Tests\Command;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestCodeCommandTest extends KernelTestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/graphify';

    private EntityManagerInterface $entityManager;
    private CommandTester $command;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge RESTART IDENTITY CASCADE');

        $this->entityManager->persist(new Project('demo', 'Demo', '/repos/demo'));
        $this->entityManager->flush();

        $this->command = new CommandTester((new Application($kernel))->find('app:ingest:code'));
    }

    public function testImportsCodeFilesSymbolsAndEdges(): void
    {
        $this->command->execute(['project' => 'demo', 'graph' => self::FIXTURES.'/graph-v1.json']);

        $this->command->assertCommandIsSuccessful();
        self::assertStringContainsString('nodes 5 created, 0 updated, 0 deleted; edges 6 created, 0 deleted', $this->display());

        $catalog = $this->findNode('src_domain_catalog');
        self::assertSame(NodeType::CodeFile, $catalog->getType());
        self::assertSame('src/domain/catalog.ts', $catalog->getPath());
        self::assertSame(['community' => 1, 'ref' => str_repeat('a', 40)], $catalog->getMetadata());

        $planMerges = $this->findNode('src_domain_catalog_planmerges');
        self::assertSame(NodeType::Symbol, $planMerges->getType());
        self::assertSame(42, $planMerges->getLine());

        self::assertNull($this->entityManager->getRepository(Node::class)->findOneBy(['externalId' => 'svelte']));
        self::assertNull($this->entityManager->getRepository(Node::class)->findOneBy(['externalId' => 'ref_hono']));

        $inferred = $this->findEdge('src_main_run', 'src_domain_catalog_merge', 'indirect_call');
        self::assertSame(Confidence::Inferred, $inferred->getConfidence());
        self::assertSame(EdgeOrigin::Ast, $inferred->getOrigin());
        self::assertSame('src/main.ts:L7', $inferred->getEvidence());
    }

    public function testReimportUpdatesNodesAndRemovesWhatDisappeared(): void
    {
        $this->command->execute(['project' => 'demo', 'graph' => self::FIXTURES.'/graph-v1.json']);
        $this->entityManager->clear();

        $this->command->execute(['project' => 'demo', 'graph' => self::FIXTURES.'/graph-v2.json']);

        $this->command->assertCommandIsSuccessful();
        self::assertStringContainsString('nodes 0 created, 4 updated, 1 deleted; edges 0 created, 3 deleted', $this->display());

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Node::class)->findOneBy(['externalId' => 'src_domain_catalog_merge']));
        self::assertSame(50, $this->findNode('src_domain_catalog_planmerges')->getLine());
        self::assertSame(3, $this->entityManager->getRepository(Edge::class)->count());
    }

    public function testFailsOnUnknownProject(): void
    {
        $status = $this->command->execute(['project' => 'missing', 'graph' => self::FIXTURES.'/graph-v1.json']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Project "missing" not found', $this->command->getDisplay());
    }

    private function display(): string
    {
        return preg_replace('/\s+/', ' ', $this->command->getDisplay());
    }

    private function findNode(string $externalId): Node
    {
        $node = $this->entityManager->getRepository(Node::class)->findOneBy(['externalId' => $externalId]);
        self::assertNotNull($node, \sprintf('Node "%s" not imported.', $externalId));

        return $node;
    }

    private function findEdge(string $source, string $target, string $relation): Edge
    {
        $edge = $this->entityManager->createQuery(
            'SELECT e FROM App\Entity\Edge e JOIN e.source s JOIN e.target t WHERE s.externalId = :source AND t.externalId = :target AND e.relation = :relation'
        )
            ->setParameters(['source' => $source, 'target' => $target, 'relation' => $relation])
            ->getOneOrNullResult();
        self::assertNotNull($edge, \sprintf('Edge %s -[%s]-> %s not imported.', $source, $relation, $target));

        return $edge;
    }
}
