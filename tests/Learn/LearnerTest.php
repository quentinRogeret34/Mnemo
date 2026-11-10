<?php

namespace App\Tests\Learn;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Graph\KnowledgeReader;
use App\Ingest\GraphifyImporter;
use App\Learn\Learner;
use App\Learn\SourceKind;
use App\Memory\MemoryStore;
use App\Proposal\ProposalApplier;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LearnerTest extends KernelTestCase
{
    use LlmTestTrait;

    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $this->project = new Project('demo', 'Demo', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($this->project, __DIR__.'/../Fixtures/graphify/graph-v1.json');
    }

    public function testDropsLowScoresAndProposesNewLearningsWithoutTouchingTheGraph(): void
    {
        $this->scriptLlm(fn (): array => $this->extraction([
            $this->candidate('Le catalogue est chargé une seule fois au démarrage dans src/domain/catalog.ts.', files: ['src/domain/catalog.ts', 'src/nope.ts']),
            $this->candidate('Il faisait beau ce jour-là.', score: 1),
        ]));

        $report = $this->learn('conversation text');

        self::assertSame(['extracted' => 2, 'dropped_low_score' => 1, 'covered' => 0, 'already_proposed' => 0, 'proposed' => 1, 'truncated' => false], $report);
        self::assertCount(1, $this->llmCalls, 'No neighbour memory: reconciliation must not call the LLM.');
        self::assertSame(0, $this->entityManager->getRepository(Node::class)->count(['type' => NodeType::Memory]));

        $proposal = $this->entityManager->getRepository(Proposal::class)->findOneBy([]);
        self::assertSame('new', $proposal->getPayload()['reconciliation']);
        self::assertSame(8.0, $proposal->getPayload()['score']);
    }

    public function testAppliedNewLearningIsAttachedAndLinkedAsInferred(): void
    {
        $this->scriptLlm(fn (): array => $this->extraction([
            $this->candidate('Le catalogue est chargé une seule fois au démarrage.', files: ['src/domain/catalog.ts', 'src/nope.ts']),
        ]));
        $this->learn('conversation text');

        $memory = self::getContainer()->get(ProposalApplier::class)->apply($this->entityManager->getRepository(Proposal::class)->findOneBy([]), ['lesson' => '  Le catalogue est chargé au démarrage.  ']);

        self::assertSame('Le catalogue est chargé au démarrage.', $memory->getLabel());
        self::assertSame('learn', $memory->getMetadata()['source']);
        self::assertTrue($memory->getProjects()->contains($this->project));
        $edge = $this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $memory, 'relation' => 'about']);
        self::assertSame('src/domain/catalog.ts', $edge->getTarget()->getPath());
        self::assertSame(Confidence::Inferred, $edge->getConfidence());
        self::assertSame(EdgeOrigin::Llm, $edge->getOrigin());
    }

    public function testCoveredLearningIsNotProposed(): void
    {
        $existing = $this->givenMemory('Les remises sont plafonnées à 10 % sur tout le catalogue.');
        $this->scriptLlm(fn (string $system): array => str_contains($system, 'Tu compares')
            ? ['verdict' => 'covered', 'target_id' => $existing->getId(), 'reason' => 'Déjà connu.']
            : $this->extraction([$this->candidate('Les remises du catalogue sont plafonnées à 10 %.')]));

        $report = $this->learn('conversation text');

        self::assertSame(1, $report['covered']);
        self::assertSame(0, $report['proposed']);
        self::assertStringContainsString(\sprintf('[%d] Les remises sont plafonnées', $existing->getId()), $this->llmCalls[1]['user']);
    }

    public function testSupersedingLearningArchivesTheOldMemoryWithALink(): void
    {
        $existing = $this->givenMemory('Les remises sont plafonnées à 10 % sur tout le catalogue.');
        $this->scriptLlm(fn (string $system): array => str_contains($system, 'Tu compares')
            ? ['verdict' => 'supersedes', 'target_id' => $existing->getId(), 'reason' => 'Le plafond change.']
            : $this->extraction([$this->candidate('Les remises du catalogue sont plafonnées à 15 %.', type: 'decision', adr: ['context' => 'Concurrence', 'decision' => 'Plafond à 15 %', 'alternatives' => '', 'consequences' => 'Marges réduites'])]));
        $this->learn('diff text');

        $memory = self::getContainer()->get(ProposalApplier::class)->apply($this->entityManager->getRepository(Proposal::class)->findOneBy([]));

        self::assertSame(MemoryStore::STATUS_SUPERSEDED, $existing->getMetadata()['status']);
        self::assertSame('SUPERSEDED', end($existing->getMetadata()['history'])['event']);
        self::assertSame(['context' => 'Concurrence', 'decision' => 'Plafond à 15 %', 'consequences' => 'Marges réduites'], $memory->getMetadata()['adr']);
        self::assertNotNull($this->entityManager->getRepository(Edge::class)->findOneBy(['source' => $memory, 'target' => $existing, 'relation' => 'supersedes']));

        $reader = self::getContainer()->get(KnowledgeReader::class);
        self::assertSame([$memory->getId()], array_column($reader->memories($this->project), 'id'));
        self::assertSame([$existing->getId()], array_column($reader->memories($this->project, archived: true), 'id'));
        self::assertSame([['id' => $existing->getId(), 'text' => $existing->getLabel(), 'status' => 'superseded']], $reader->supersession($memory)['replaces']);
        self::assertNull(self::getContainer()->get(MemoryStore::class)->findByText($existing->getLabel()));
    }

    public function testTargetOutsideTheNeighboursFallsBackToNew(): void
    {
        $this->givenMemory('Les remises sont plafonnées à 10 % sur tout le catalogue.');
        $this->scriptLlm(fn (string $system): array => str_contains($system, 'Tu compares')
            ? ['verdict' => 'updates', 'target_id' => 9999, 'reason' => 'Inventé.']
            : $this->extraction([$this->candidate('Les remises du catalogue sont plafonnées à 10 % hors soldes.')]));

        $this->learn('conversation text');

        $payload = $this->entityManager->getRepository(Proposal::class)->findOneBy([])->getPayload();
        self::assertSame('new', $payload['reconciliation']);
        self::assertNull($payload['target_id']);
    }

    public function testAPendingLessonIsNotProposedTwice(): void
    {
        $this->scriptLlm(fn (): array => $this->extraction([$this->candidate('Le catalogue est chargé une seule fois au démarrage.')]));

        $this->learn('first run');
        $report = $this->learn('second run');

        self::assertSame(1, $report['already_proposed']);
        self::assertSame(1, $this->entityManager->getRepository(Proposal::class)->count([]));
    }

    /**
     * @return array{extracted: int, dropped_low_score: int, covered: int, already_proposed: int, proposed: int, truncated: bool}
     */
    private function learn(string $text): array
    {
        return self::getContainer()->get(Learner::class)->learn(SourceKind::Conversation, 'test', $text, $this->project);
    }

    private function givenMemory(string $text): Node
    {
        $memory = self::getContainer()->get(MemoryStore::class)->add($text, MemoryType::Convention, 'manual');
        $memory->addProject($this->project);
        $this->entityManager->flush();

        return $memory;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return array{candidates: list<array<string, mixed>>}
     */
    private function extraction(array $candidates): array
    {
        return ['candidates' => $candidates];
    }

    /**
     * @param list<string>          $files
     * @param array<string, string> $adr
     *
     * @return array<string, mixed>
     */
    private function candidate(string $lesson, int $score = 8, string $type = 'convention', array $files = [], array $adr = []): array
    {
        return [
            'lesson' => $lesson,
            'evidence' => 'cité dans la source',
            'type' => $type,
            'durability' => $score,
            'reuse' => $score,
            'project_fit' => $score,
            'risk' => $score,
            'files' => $files,
            'rules' => [],
            'adr' => $adr + ['context' => '', 'decision' => '', 'alternatives' => '', 'consequences' => ''],
        ];
    }
}
