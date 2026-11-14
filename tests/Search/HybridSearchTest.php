<?php

namespace App\Tests\Search;

use App\Entity\Project;
use App\Enum\MemoryType;
use App\Llm\LlmSettings;
use App\Memory\MemoryStore;
use App\Message\IndexNode;
use App\MessageHandler\IndexNodeHandler;
use App\Search\Embedder;
use App\Search\HybridSearch;
use App\Search\NodeIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HybridSearchTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');
        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
    }

    public function testWithoutEmbeddingsTheSearchStaysOnKeywords(): void
    {
        $this->memory('Les remises sont plafonnées à 10 %.');

        $found = self::getContainer()->get(HybridSearch::class)->search('remises', $this->project, ['memory'], 10);
        self::assertSame(HybridSearch::KEYWORD, $found['mode']);
        self::assertCount(1, $found['results']);
    }

    public function testIndexedMemoriesAreFoundByMeaningAndReindexedOnlyWhenTheyChange(): void
    {
        // Two axes stand in for meaning: pricing words and deployment words.
        $embedder = new class(self::getContainer()->get(LlmSettings::class)) extends Embedder {
            public int $calls = 0;

            public function isAvailable(): bool
            {
                return true;
            }

            public function model(?Project $project = null): string
            {
                return 'test/axes';
            }

            public function embed(string $text, ?Project $project = null): array
            {
                ++$this->calls;
                $count = static fn (string $pattern): float => (float) preg_match_all($pattern, mb_strtolower($text));

                return [$count('/remise|rabais|réduction|prix/u') + 0.01, $count('/déploi|production|serveur/u') + 0.01];
            }
        };
        self::getContainer()->set(Embedder::class, $embedder);

        $pricing = $this->memory('Les remises sont plafonnées à 10 %.');
        $deploy = $this->memory('Le déploiement en production passe par la CI.');
        $queued = array_map(static fn ($envelope): object => $envelope->getMessage(), self::getContainer()->get('messenger.transport.async')->getSent());
        self::assertEquals([new IndexNode($pricing), new IndexNode($deploy)], $queued);

        $handler = self::getContainer()->get(IndexNodeHandler::class);
        array_map($handler, $queued);
        self::assertSame(2, $embedder->calls);
        self::assertSame(['indexed' => 0, 'unchanged' => 2], self::getContainer()->get(NodeIndexer::class)->indexAll($this->project));
        self::assertSame(2, $embedder->calls);

        $search = self::getContainer()->get(HybridSearch::class);
        $found = $search->search('rabais', $this->project, ['memory', 'code_file'], 10);
        self::assertSame(HybridSearch::HYBRID, $found['mode']);
        self::assertSame($pricing, $found['results'][0]['id']);

        self::assertSame($deploy, $search->related('Mise en production du serveur', $this->project, ['memory'], 1)[0]['id']);
        self::assertSame([], $search->related('Mise en production du serveur', $this->project, ['memory'], 1, [$deploy, $pricing]));
    }

    private function memory(string $text): int
    {
        $memory = self::getContainer()->get(MemoryStore::class)->add($text, MemoryType::Convention, 'manual');
        $memory->addProject($this->project);
        $this->entityManager->flush();

        return $memory->getId();
    }
}
