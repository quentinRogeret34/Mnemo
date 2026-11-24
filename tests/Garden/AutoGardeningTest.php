<?php

namespace App\Tests\Garden;

use App\Dashboard\DashboardReader;
use App\Entity\ChatConversation;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Llm\LlmSettings;
use App\Memory\MemoryStore;
use App\Message\GardenProject;
use App\Message\RunAutoGardening;
use App\MessageHandler\GardenProjectHandler;
use App\MessageHandler\RunAutoGardeningHandler;
use App\Proposal\ProposalApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AutoGardeningTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, proposal, chat_conversation RESTART IDENTITY CASCADE');
        /** @var InMemoryTransport $async */
        $async = self::getContainer()->get('messenger.transport.async');
        $async->reset();
    }

    public function testSchedulerDispatchesOnlyActiveOptInProjects(): void
    {
        $optIn = new Project('opt-in', 'Opt-in', '/repos/opt-in');
        $optIn->setAutoGarden(true);
        $optOut = new Project('opt-out', 'Opt-out', '/repos/opt-out');
        $archived = new Project('old', 'Old', '/repos/old');
        $archived->setAutoGarden(true);
        $archived->archive();

        $this->entityManager->persist($optIn);
        $this->entityManager->persist($optOut);
        $this->entityManager->persist($archived);
        $this->entityManager->flush();

        self::getContainer()->get(RunAutoGardeningHandler::class)(new RunAutoGardening());

        /** @var InMemoryTransport $async */
        $async = self::getContainer()->get('messenger.transport.async');
        $sent = $async->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(GardenProject::class, $message);
        self::assertSame($optIn->getId(), $message->projectId);
    }

    public function testGardenProjectVerifiesStaleMemoriesDetectsDuplicatesAndRecordsTimestamp(): void
    {
        $project = new Project('demo', 'Demo', '/repos/demo');
        $project->setAutoGarden(true);
        $this->entityManager->persist($project);

        $file = new Node($project, NodeType::CodeFile, 'src/Pricing.php', 'Pricing.php', 'src/Pricing.php');
        $rule = new Node($project, NodeType::BusinessRule, 'RG-001', 'Plafond remise', null, null, ['statement' => 'Le plafond des remises est désormais à 20 %.', 'status' => 'validated']);
        $commit = new Node($project, NodeType::Commit, 'abc123', 'Update pricing', null, null, ['date' => '2026-11-24T10:00:00+00:00']);
        $this->entityManager->persist($file);
        $this->entityManager->persist($rule);
        $this->entityManager->persist($commit);
        $this->entityManager->flush();

        $this->entityManager->persist(new Edge($commit, $file, 'touches', Confidence::Extracted, EdgeOrigin::Git));

        $store = self::getContainer()->get(MemoryStore::class);
        $stale = $store->add('Les remises sont plafonnées à 10 % dans Pricing.php.', MemoryType::Convention, 'manual');
        $stale->addProject($project);
        $stale->annotate([
            'verification' => [
                'verdict' => 'verified',
                'tier' => 'code',
                'sources' => [],
                'reason' => 'OK précédemment.',
                'text' => $stale->getLabel(),
                'at' => '2026-11-20T10:00:00+00:00',
            ],
        ] + $stale->getMetadata());
        $second = $store->add('Les remises ne dépassent jamais 10 %.', MemoryType::Convention, 'manual');
        $second->addProject($project);
        $this->entityManager->flush();
        $this->entityManager->persist(new Edge($stale, $file, 'about', Confidence::Extracted, EdgeOrigin::Human));
        $this->entityManager->persist(new Edge($stale, $rule, 'about', Confidence::Extracted, EdgeOrigin::Human));
        $this->entityManager->flush();

        $platform = MockPlatformFactory::createPlatform(function (Model $model, array|string|object $input) use ($stale, $second, $rule): string {
            $system = \is_array($input) ? (string) ($input['messages'][0]['content'] ?? '') : '';
            if (str_contains($system, 'duplicate')) {
                return json_encode(['pairs' => [[
                    'a_id' => $stale->getId(),
                    'b_id' => $second->getId(),
                    'kind' => 'duplicate',
                    'reason' => 'Même plafond de remise.',
                ]]], \JSON_THROW_ON_ERROR);
            }

            return json_encode(['verdict' => 'refuted', 'source_ids' => [$rule->getId()], 'reason' => 'Le plafond a changé à 20 %.'], \JSON_THROW_ON_ERROR);
        });
        self::getContainer()->set(LlmSettings::class, new LlmSettings(
            new ServiceLocator(['openrouter' => static fn () => $platform]),
            'openrouter',
            'test-model',
            'disabled',
            'test-embedding',
            'test-key',
        ));

        self::getContainer()->get(GardenProjectHandler::class)(new GardenProject((int) $project->getId()));

        $this->entityManager->clear();
        $reloadedProject = $this->entityManager->find(Project::class, $project->getId());
        self::assertNotNull($reloadedProject?->getLastAutoGardenAt());

        $proposals = $this->entityManager->getRepository(Proposal::class)->findBy(['project' => $reloadedProject]);
        $kinds = array_map(static fn (Proposal $p): ProposalKind => $p->getKind(), $proposals);
        self::assertContains(ProposalKind::Refuted, $kinds);
        self::assertContains(ProposalKind::Duplicate, $kinds);
    }

    public function testDashboardReaderComputesAiQualityMetrics(): void
    {
        $project = new Project('demo', 'Demo', '/repos/demo');
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        $applier = self::getContainer()->get(ProposalApplier::class);
        $p1 = new Proposal($project, ProposalKind::Learning, [
            'lesson' => 'Original 1',
            'type' => 'convention',
            'reconciliation' => 'new',
            'score' => 8.0,
            'evidence' => 'src',
            'source' => ['kind' => 'conversation', 'label' => 'test'],
            'files' => [],
            'rules' => [],
            'adr' => [],
        ]);
        $p2 = new Proposal($project, ProposalKind::Learning, [
            'lesson' => 'Original 2',
            'type' => 'fact',
            'reconciliation' => 'new',
            'score' => 7.0,
            'evidence' => 'src',
            'source' => ['kind' => 'conversation', 'label' => 'test'],
            'files' => [],
            'rules' => [],
            'adr' => [],
        ]);
        $p3 = new Proposal($project, ProposalKind::Learning, [
            'lesson' => 'Bruit',
            'type' => 'fact',
            'reconciliation' => 'new',
            'score' => 5.0,
            'evidence' => 'src',
            'source' => ['kind' => 'conversation', 'label' => 'test'],
            'files' => [],
            'rules' => [],
            'adr' => [],
        ]);
        $this->entityManager->persist($p1);
        $this->entityManager->persist($p2);
        $this->entityManager->persist($p3);
        $this->entityManager->flush();

        $applier->apply($p1, ['lesson' => 'Reformulé 1']);
        $applier->apply($p2);
        $applier->reject($p3, 'too_obvious');

        $conv = new ChatConversation($project, 'local', 'Question 1');
        $conv->appendExchange('Question 1', 'Réponse 1', []);
        $conv->appendExchange('Question 2', 'Réponse 2', []);
        $messages = $conv->getMessages();
        $conv->toggleFeedback($messages[1]['id'], 'up');
        $conv->toggleFeedback($messages[3]['id'], 'down');
        $this->entityManager->persist($conv);
        $this->entityManager->flush();

        $quality = self::getContainer()->get(DashboardReader::class)->aiQuality($project, new \DateTimeImmutable('-1 day'));

        self::assertSame(2, $quality['applied']);
        self::assertSame(1, $quality['rejected']);
        self::assertSame(1, $quality['edited']);
        self::assertSame(66.7, $quality['acceptance_rate']);
        self::assertSame(1, $quality['thumbs_up']);
        self::assertSame(1, $quality['thumbs_down']);
        self::assertSame(50.0, $quality['satisfaction_rate']);
    }
}
