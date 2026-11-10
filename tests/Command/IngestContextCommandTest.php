<?php

namespace App\Tests\Command;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class IngestContextCommandTest extends KernelTestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/repo';

    private EntityManagerInterface $entityManager;
    private CommandTester $command;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $this->entityManager->persist(new Project('demo', 'Demo', '/repos/demo'));
        $this->entityManager->flush();

        $this->command = new CommandTester((new Application($kernel))->find('app:ingest:context'));
    }

    public function testImportsTheBankDecisionsAndConventions(): void
    {
        $this->command->execute(['project' => 'demo', 'path' => self::FIXTURE]);

        $this->command->assertCommandIsSuccessful();
        self::assertStringContainsString('Memories 4 created, 0 unchanged; decisions 2 created, 0 updated, 0 unchanged; documents 4 created', preg_replace('/\s+/', ' ', $this->command->getDisplay()));

        $memories = $this->entityManager->getRepository(Node::class)->findBy(['type' => NodeType::Memory], ['id' => 'ASC']);
        self::assertSame([
            'Architecture / Stack : Symfony with Doctrine ORM',
            'Architecture / Stack : Postgres for everything, vectors included through pgvector',
            'Architecture / Gotchas : The cart is recalculated on each addition: see `src/domain/catalog.ts`.',
            'Coding assertions : Controllers stay thin and delegate to services.',
            'Store everything in MySQL',
            'Store everything in Postgres',
        ], array_map(static fn (Node $memory): string => $memory->getLabel(), $memories));
        self::assertSame('convention', $memories[3]->getMetadata()['type']);
        self::assertSame(['demo'], $memories[0]->getProjects()->map(static fn (Project $project): string => $project->getSlug())->getValues());

        [$mysql, $postgres] = [$memories[4], $memories[5]];
        self::assertSame('decision', $postgres->getMetadata()['type']);
        self::assertSame([
            'context' => 'Vectors are needed for semantic search.',
            'decision' => 'Postgres with pgvector.',
            'alternatives' => 'A separate vector database: one more service to run.',
            'consequences' => 'Migration of the existing data.',
        ], $postgres->getMetadata()['adr']);
        self::assertSame(MemoryStore::STATUS_SUPERSEDED, $mysql->getMetadata()['status']);
        self::assertNotNull($this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $postgres, 'target' => $mysql, 'relation' => 'supersedes']));

        $documents = [];
        foreach ($this->entityManager->getRepository(Node::class)->findBy(['type' => NodeType::Document]) as $document) {
            $documents[$document->getLabel()] = $document;
        }
        ksort($documents);
        self::assertSame(['.cursor/rules/style.mdc', 'CLAUDE.md', 'aidd_docs/memory/internal/notes.md', 'aidd_docs/tasks/2026_10/2026_10_01_cart/plan.md'], array_keys($documents));
        self::assertSame('Plans', $documents['aidd_docs/tasks/2026_10/2026_10_01_cart/plan.md']->getFolder()->getName());
        self::assertSame('demo', $documents['CLAUDE.md']->getFolder()->getName());
        self::assertSame('Conventions agents', $documents['CLAUDE.md']->getFolder()->getParent()->getName());
    }

    public function testReimportChangesNothingAndReportsWhatDisappeared(): void
    {
        $copy = sys_get_temp_dir().'/repo-context-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->mirror(self::FIXTURE, $copy);
        try {
            $this->command->execute(['project' => 'demo', 'path' => $copy]);
            $filesystem->remove($copy.'/CLAUDE.md');
            $filesystem->dumpFile($copy.'/aidd_docs/memory/internal/decisions/use-postgres.md', str_replace('Postgres with pgvector.', 'Postgres 18 with pgvector.', (string) file_get_contents($copy.'/aidd_docs/memory/internal/decisions/use-postgres.md')));

            $this->command->execute(['project' => 'demo', 'path' => $copy]);
        } finally {
            $filesystem->remove($copy);
        }

        $display = preg_replace('/\s+/', ' ', $this->command->getDisplay());
        self::assertStringContainsString('Memories 0 created, 4 unchanged; decisions 0 created, 1 updated, 1 unchanged; documents 0 created, 0 updated, 3 unchanged', $display);
        self::assertStringContainsString('CLAUDE.md', $display);
        self::assertSame(1, $this->entityManager->getRepository(Node::class)->count(['type' => NodeType::Document, 'label' => 'CLAUDE.md']));
    }
}
