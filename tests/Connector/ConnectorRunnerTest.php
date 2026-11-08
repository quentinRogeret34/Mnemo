<?php

namespace App\Tests\Connector;

use App\Connector\ConnectorRunner;
use App\Document\DocumentStorage;
use App\Entity\Connector;
use App\Entity\Folder;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorTransport;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ConnectorRunnerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ConnectorRunner $runner;
    private Project $project;
    private Folder $folder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');
        $this->runner = self::getContainer()->get(ConnectorRunner::class);

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->folder = new Folder('Notion');
        $this->entityManager->persist($this->project);
        $this->entityManager->persist($this->folder);
        $this->entityManager->flush();
    }

    public function testInspectListsToolsAndResources(): void
    {
        $inspection = $this->runner->inspect($this->connector(ConnectorMode::Resources));

        self::assertSame('fixture 1.0.0', $inspection['server']);
        self::assertSame(['get_memories', 'weekly_report'], array_column($inspection['tools'], 'name'));
        self::assertSame(['notes://charte', 'config://seuils'], array_column($inspection['resources'], 'uri'));
    }

    public function testResourcesBecomeDocumentsAndOnlyChangesAreRewritten(): void
    {
        $connector = $this->connector(ConnectorMode::Resources);

        self::assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'error' => null], $this->runner->sync($connector));

        $documents = $this->documents();
        self::assertSame(['Charte qualité', 'Seuils de détection'], array_map(static fn (Node $document): string => $document->getLabel(), $documents));
        self::assertSame('Notion', $documents[0]->getFolder()->getName());
        self::assertSame(['demo'], $documents[0]->getProjects()->map(static fn (Project $project): string => $project->getSlug())->getValues());
        self::assertStringContainsString("```json\n{\"pente_minimale\": 4}\n```", self::getContainer()->get(DocumentStorage::class)->markdownBody($documents[1]));

        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'skipped' => [], 'error' => null], $this->runner->sync($connector));

        $_SERVER['CHARTE_VERSION'] = 'v2';
        $connector->setCredentials(['FIXTURE_CHARTE' => "# Charte qualité env:CHARTE_VERSION\n\nNouvelle version."]);
        self::assertSame(1, $this->runner->sync($connector)['updated']);
        $this->entityManager->clear();
        self::assertSame(['Charte qualité v2', 'Seuils de détection'], array_map(static fn (Node $document): string => $document->getLabel(), $this->documents()));
        self::assertNotNull($this->entityManager->getRepository(Connector::class)->find($connector->getId())->getLastRunAt());
        unset($_SERVER['CHARTE_VERSION']);
    }

    public function testToolAnswerBecomesMemoriesWithoutDuplicates(): void
    {
        $connector = $this->connector(ConnectorMode::ToolMemories);
        $connector->setTool('get_memories');
        $connector->setItemsPath('results');
        $connector->setMemoryType(MemoryType::Preference);

        $report = $this->runner->sync($connector);
        self::assertSame(2, $report['created']);
        self::assertSame(['élément 2 (texte absent ou de plus de 1000 caractères)'], $report['skipped']);
        self::assertSame(2, $this->runner->sync($connector)['unchanged']);

        $memory = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::Memory, 'label' => 'Utilise PostgreSQL 18 avec pgvector.']);
        self::assertSame(['type' => 'preference', 'source' => 'connector', 'connector' => 'Fixture'], array_intersect_key($memory->getMetadata(), ['type' => 1, 'source' => 1, 'connector' => 1]));
        self::assertTrue($memory->getProjects()->contains($this->project));
    }

    public function testToolAnswerBecomesOneDocument(): void
    {
        $connector = $this->connector(ConnectorMode::ToolDocument);
        $connector->setTool('weekly_report');

        self::assertSame(1, $this->runner->sync($connector)['created']);
        self::assertSame('Fixture', $this->documents()[0]->getLabel());
        self::assertSame('Semaine calme : 3 tickets fermés.', trim(self::getContainer()->get(DocumentStorage::class)->markdownBody($this->documents()[0])));
    }

    public function testFailuresAreReportedNotThrown(): void
    {
        $connector = $this->connector(ConnectorMode::ToolMemories);
        $connector->setTool('get_memories');
        $connector->setItemsPath('missing');
        self::assertStringContainsString('ne contient pas de liste au chemin "missing"', $this->runner->sync($connector)['error']);

        $connector->setEndpoint('php does-not-exist.php');
        self::assertNotNull($this->runner->sync($connector)['error']);

        $connector->setTransport(ConnectorTransport::Http);
        $connector->setEndpoint('http://127.0.0.1:1/mcp');
        self::assertNotNull($this->runner->sync($connector)['error']);
    }

    private function connector(ConnectorMode $mode): Connector
    {
        $connector = new Connector();
        $connector->setName('Fixture');
        $connector->setTransport(ConnectorTransport::Stdio);
        $connector->setEndpoint('php tests/Fixtures/mcp/server.php');
        $connector->setMode($mode);
        $connector->setProject($this->project);
        $connector->setFolder($this->folder);
        $this->entityManager->persist($connector);
        $this->entityManager->flush();

        return $connector;
    }

    /**
     * @return list<Node>
     */
    private function documents(): array
    {
        return $this->entityManager->getRepository(Node::class)->findBy(['type' => NodeType::Document], ['label' => 'ASC']);
    }
}
