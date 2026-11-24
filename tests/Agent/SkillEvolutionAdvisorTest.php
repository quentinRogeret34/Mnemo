<?php

namespace App\Tests\Agent;

use App\Entity\AgentAsset;
use App\Entity\McpCall;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\AgentAssetKind;
use App\Enum\MemoryType;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Memory\MemoryStore;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SkillEvolutionAdvisorTest extends WebTestCase
{
    use LlmTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, proposal, agent_asset, mcp_call RESTART IDENTITY CASCADE');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
    }

    public function testProposesNewAndUpdatedSkillsAndAppliesThemThroughProposalsQueue(): void
    {
        $store = self::getContainer()->get(MemoryStore::class);
        $m1 = $store->add('Toujours utiliser LockFactory pour les opérations concurrentes sur les règles.', MemoryType::Convention, 'manual');
        $m1->addProject($this->project);
        $m2 = $store->add('Les migrations Doctrine ne doivent jamais utiliser doctrine:schema:update.', MemoryType::Learning, 'learn');
        $m2->addProject($this->project);

        $existing = new AgentAsset($this->project);
        $existing->setKind(AgentAssetKind::Skill);
        $existing->setName('symfony-guardrails');
        $existing->setDescription('Conventions Symfony du projet.');
        $existing->setContent('Utiliser les attributs PHP pour les routes.');
        $this->entityManager->persist($existing);
        $this->entityManager->persist(new McpCall('search', 'demo', 'LockFactory migration', ['memory'], 2, 2, false, 15, 'claude-code'));
        $this->entityManager->flush();

        $this->scriptLlm(static fn (): array => ['proposals' => [
            [
                'action' => 'update',
                'kind' => 'skill',
                'name' => 'symfony-guardrails',
                'description' => 'Conventions Symfony et garde-fous base de données.',
                'content' => "- Utiliser les attributs PHP pour les routes.\n- Toujours passer par des migrations Doctrine, jamais schema:update.",
                'reason' => 'Intègre la règle récurrente sur les migrations Doctrine.',
                'memory_ids' => [$m2->getId()],
            ],
            [
                'action' => 'create',
                'kind' => 'skill',
                'name' => 'concurrency-locks',
                'description' => 'Bonnes pratiques de concurrence avec Symfony Lock.',
                'content' => 'Toujours utiliser symfony/lock (LockFactory) pour les sections critiques.',
                'reason' => 'Convention répétée dans la mémoire projet.',
                'memory_ids' => [$m1->getId()],
            ],
        ]]);

        $crawler = $this->client->request('GET', '/projects/demo/agent-assets');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Proposer des évolutions IA')->form());
        self::assertResponseRedirects('/proposals?project=demo&kind=skill_update');
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.flash', '2 propositions de skills ajoutées');
        self::assertCount(2, $crawler->filter('.proposal'));
        self::assertStringContainsString('LockFactory', $this->llmCalls[0]['user']);
        self::assertStringContainsString('symfony-guardrails', $this->llmCalls[0]['user']);

        // Second run deduplicates pending skill updates.
        $crawlerIndex = $this->client->request('GET', '/projects/demo/agent-assets');
        $this->client->submit($crawlerIndex->selectButton('Proposer des évolutions IA')->form());
        self::assertResponseRedirects('/projects/demo/agent-assets');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Aucune évolution de skill');

        // Apply the update proposal (with human edit) and the create proposal.
        $crawler = $this->client->request('GET', '/proposals?project=demo&kind=skill_update');
        $firstForm = $crawler->filter('.proposal')->first()->selectButton('Appliquer ce skill')->form();
        $firstForm['content'] = "- Utiliser les attributs PHP.\n- Toujours générer une migration Doctrine propre.";
        $this->client->submit($firstForm);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Skill « symfony-guardrails » appliqué');

        $secondForm = $crawler->filter('.proposal')->first()->selectButton('Appliquer ce skill')->form();
        $this->client->submit($secondForm);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Skill « concurrency-locks » appliqué');

        $this->entityManager->clear();
        $assets = $this->entityManager->getRepository(AgentAsset::class)->findBy(['project' => $this->project], ['name' => 'ASC']);
        self::assertCount(2, $assets);
        self::assertSame('concurrency-locks', $assets[0]->getName());
        self::assertSame('symfony-guardrails', $assets[1]->getName());
        self::assertStringContainsString('migration Doctrine propre', $assets[1]->getContent());

        $applied = $this->entityManager->getRepository(Proposal::class)->findBy([
            'project' => $this->project,
            'kind' => ProposalKind::SkillUpdate,
            'status' => ProposalStatus::Applied,
        ], ['id' => 'ASC']);
        self::assertCount(2, $applied);
        self::assertTrue((bool) ($applied[0]->getPayload()['human_feedback']['edited'] ?? false));
        self::assertFalse((bool) ($applied[1]->getPayload()['human_feedback']['edited'] ?? true));
    }
}
