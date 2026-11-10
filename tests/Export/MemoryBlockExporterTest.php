<?php

namespace App\Tests\Export;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Export\MemoryBlockExporter;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class MemoryBlockExporterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $this->project = new Project('demo', 'Demo', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->persist(new Node($this->project, NodeType::CodeFile, 'src/Controller/CartController.php', 'CartController.php', 'src/Controller/CartController.php'));
        $this->entityManager->persist(new Node($this->project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'validated']));
        $this->entityManager->persist(new Node($this->project, NodeType::BusinessRule, 'RG-043', 'Brouillon', null, null, ['statement' => 'Pas encore validée.', 'status' => 'to_review']));

        $store = self::getContainer()->get(MemoryStore::class);
        foreach ([
            ['Controllers stay thin.', MemoryType::Convention, []],
            ['Store everything in Postgres', MemoryType::Decision, ['decision' => 'Postgres with pgvector.']],
            ['Old convention, superseded.', MemoryType::Convention, []],
            ['Refuted convention.', MemoryType::Convention, []],
            ['A plain fact, not exported.', MemoryType::Fact, []],
        ] as [$text, $type, $adr]) {
            $memory = $store->add($text, $type, 'manual');
            $memory->addProject($this->project);
            $store->setAdr($memory, $adr);
        }
        $this->entityManager->flush();
        $store->archive($store->findByText('Old convention, superseded.'), MemoryStore::STATUS_SUPERSEDED);
        $refuted = $store->findByText('Refuted convention.');
        $refuted->annotate(['verification' => ['verdict' => 'refuted']] + $refuted->getMetadata());
        $this->entityManager->flush();
    }

    public function testBlockHoldsOnlyTheTrustedDurableKnowledge(): void
    {
        $block = self::getContainer()->get(MemoryBlockExporter::class)->render($this->project);

        self::assertStringStartsWith(MemoryBlockExporter::OPEN."\n## Mémoire projet", $block);
        self::assertStringEndsWith(MemoryBlockExporter::CLOSE."\n", $block);
        self::assertStringContainsString('Capacités : API HTTP.', $block);
        self::assertStringContainsString("### Conventions\n\n- Controllers stay thin.\n", $block);
        self::assertStringContainsString('- Store everything in Postgres : Postgres with pgvector.', $block);
        self::assertStringContainsString('- `RG-042` Remise plafonnée : Une remise ne dépasse jamais 10 %.', $block);
        foreach (['RG-043', 'superseded', 'Refuted', 'plain fact'] as $absent) {
            self::assertStringNotContainsString($absent, $block);
        }
    }

    public function testCommandWritesTheBlockAndKeepsTheRestOfTheFile(): void
    {
        $dir = sys_get_temp_dir().'/memory-block-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($dir.'/CLAUDE.md', "# Instructions\n\nRun the tests.\n\n".MemoryBlockExporter::OPEN."\nold block\n".MemoryBlockExporter::CLOSE."\n\n## After\n\nKept.\n");
        $command = new CommandTester((new Application(self::$kernel))->find('app:export:memory-block'));

        try {
            $command->execute(['project' => 'demo', '--write' => [$dir.'/CLAUDE.md', $dir.'/AGENTS.md']]);
            $claude = (string) file_get_contents($dir.'/CLAUDE.md');
            $agents = (string) file_get_contents($dir.'/AGENTS.md');
            $command->execute(['project' => 'demo', '--write' => [$dir.'/CLAUDE.md']]);
        } finally {
            $filesystem->remove($dir);
        }

        self::assertStringStartsWith("# Instructions\n\nRun the tests.\n\n".MemoryBlockExporter::OPEN, $claude);
        self::assertStringEndsWith(MemoryBlockExporter::CLOSE."\n\n## After\n\nKept.\n", $claude);
        self::assertStringNotContainsString('old block', $claude);
        self::assertSame(1, substr_count($claude, MemoryBlockExporter::OPEN));
        self::assertStringStartsWith(MemoryBlockExporter::OPEN, $agents);
        self::assertStringContainsString('CLAUDE.md: unchanged', $command->getDisplay());
    }
}
