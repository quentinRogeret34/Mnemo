<?php

namespace App\Tests\Grooming;

use App\Document\DocumentStorage;
use App\Entity\GroomingRun;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Enum\UserStatus;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class GroomingSwarmTest extends WebTestCase
{
    use LlmTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;

    protected function setUp(): void
    {
        $_SERVER['APP_ACCESS_ROLE'] = $_ENV['APP_ACCESS_ROLE'] = 'PUBLIC_ACCESS';
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement(
            'TRUNCATE project, node, edge, folder, proposal, grooming_run, app_user, user_group RESTART IDENTITY CASCADE',
        );
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);

        $rule = new Node(
            $this->project,
            NodeType::BusinessRule,
            'RG-001',
            'Plafond de remise panier',
            metadata: [
                'statement' => 'Une remise panier ne dépasse jamais 10 % sans validation direction.',
                'status' => 'validated',
                'shadow_areas' => [
                    ['category' => 'exception', 'gap' => 'Cumul avec codes promo non précisé', 'question' => 'Les codes promo se cumulent-ils ?', 'severity' => 'blocker'],
                ],
            ],
        );
        $this->entityManager->persist($rule);

        $memory = new Node(
            null,
            NodeType::Memory,
            'mem-discount-ht',
            'Les remises sont calculées HT',
            metadata: ['type' => MemoryType::Convention->value, 'source' => 'manual'],
        );
        $memory->addProject($this->project);
        $this->entityManager->persist($memory);

        $codeFile = new Node(
            $this->project,
            NodeType::CodeFile,
            'file:src/Pricing/DiscountPolicy.php',
            'DiscountPolicy.php',
            'src/Pricing/DiscountPolicy.php',
        );
        $this->entityManager->persist($codeFile);

        $storage = self::getContainer()->get(DocumentStorage::class);
        $doc = $storage->storeContent(null, "# Politique remises\nLes remises panier sont plafonnées à 10 %.", 'Politique remises', 'md');
        $doc->addProject($this->project);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $_SERVER['APP_ACCESS_ROLE'] = $_ENV['APP_ACCESS_ROLE'] = 'PUBLIC_ACCESS';
        parent::tearDown();
    }

    public function testLaunchingGroomingSwarmRunsThreeAgentsPersistsRunCreatesDeduplicatedProposalsAndRendersReport(): void
    {
        $this->scriptGroomingPipeline();

        $crawler = $this->client->request('GET', '/projects/demo/grooming');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.grooming-chips', 'Plafond de remise panier');
        self::assertSelectorExists('.grooming-form');

        $form = $crawler->filter('.grooming-form')->form([
            'topic' => 'Remises et promotions',
            'context' => 'Préparation sprint Q4 : cumul codes promo et remises VIP.',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/projects/demo/grooming/\d+$#', $location);

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->llmCalls);
        self::assertStringContainsString('Plafond de remise panier', $this->llmCalls[0]['user']);
        self::assertStringContainsString('src/Pricing/DiscountPolicy.php', $this->llmCalls[0]['user']);
        self::assertStringContainsString('Cartographie existante', $this->llmCalls[1]['user']);
        self::assertStringContainsString('Cumul codes promo', $this->llmCalls[2]['user']);

        self::assertSelectorTextContains('.flash', '64 %');
        self::assertSelectorTextContains('.flash', '2 propositions créées');
        self::assertSelectorTextContains('.grooming-ring__value', '64%');
        self::assertCount(3, $crawler->filter('.grooming-agent-card'));
        self::assertCount(2, $crawler->filter('.grooming-question'));
        self::assertSelectorTextContains('.grooming-question:first-child', 'Bloquant');
        self::assertCount(2, $crawler->filter('.grooming-proposal-item'));

        $this->entityManager->clear();
        $runs = $this->entityManager->getRepository(GroomingRun::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(1, $runs);
        $run = $runs[0];
        self::assertSame(GroomingRun::STATUS_COMPLETED, $run->getStatus());
        self::assertSame(64, $run->getReadinessScore());
        self::assertCount(3, $run->getSteps());
        self::assertCount(2, $run->getQuestions());
        self::assertSame('blocking', $run->getQuestions()[0]['severity']);
        self::assertCount(2, $run->getCreatedProposalIds());

        $proposals = $this->entityManager->getRepository(Proposal::class)->findBy(
            ['project' => $this->project, 'status' => ProposalStatus::Pending],
            ['id' => 'ASC'],
        );
        self::assertCount(2, $proposals);
        self::assertSame(ProposalKind::RuleDraft, $proposals[0]->getKind());
        self::assertSame('Non-cumul des codes promotionnels', $proposals[0]->getPayload()['title']);
        self::assertSame(ProposalKind::Learning, $proposals[1]->getKind());
        self::assertSame('Valider toute remise supérieure à 10 % via un événement d’audit dédié.', $proposals[1]->getPayload()['lesson']);

        // Created proposals must render cleanly in /proposals.
        $this->client->request('GET', '/proposals?project=demo');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->client->getCrawler()->filter('.proposal-row'));

        // A second run on the same topic deduplicates against already-pending proposals.
        $this->llmCalls = [];
        $crawler = $this->client->request('GET', '/projects/demo/grooming');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.grooming-run-row'));

        $this->client->submit($crawler->filter('.grooming-form')->form([
            'topic' => 'Remises et promotions',
        ]));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'aucune proposition');

        $this->entityManager->clear();
        self::assertCount(2, $this->entityManager->getRepository(GroomingRun::class)->findAll());
        self::assertCount(2, $this->entityManager->getRepository(Proposal::class)->findAll());
    }

    public function testEnforcesProjectScopeAccessAndCsrfProtection(): void
    {
        $otherProject = new Project('other', 'Other project', '/repos/other');
        $this->entityManager->persist($otherProject);
        $otherRun = new GroomingRun($otherProject, 'Secret topic');
        $otherRun->complete(80, 'Secret summary', [], [], []);
        $this->entityManager->persist($otherRun);

        $bob = new User('bob@example.com', 'Bob', 'github', 'bob-1');
        $bob->setStatus(UserStatus::Active);
        $group = new UserGroup('Demo team');
        $group->setUsers([$bob]);
        $group->setProjects([$this->project]);
        $this->entityManager->persist($bob);
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        $otherRunId = $otherRun->getId();
        $bobId = $bob->getId();

        $_SERVER['APP_ACCESS_ROLE'] = $_ENV['APP_ACCESS_ROLE'] = 'ROLE_USER';
        $this->entityManager->clear();
        $this->client->loginUser($this->entityManager->find(User::class, $bobId));

        $this->client->request('GET', '/projects/demo/grooming');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/projects/other/grooming');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/projects/other/grooming', ['topic' => 'Leak', '_token' => 'bad']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/projects/other/grooming/'.$otherRunId);
        self::assertResponseStatusCodeSame(404);

        // Cross-project run ID isolation under an allowed project slug.
        $this->client->request('GET', '/projects/demo/grooming/'.$otherRunId);
        self::assertResponseStatusCodeSame(404);

        // Invalid CSRF token on allowed project is rejected.
        $this->client->request('POST', '/projects/demo/grooming', ['topic' => 'Remises', '_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(403);
    }

    private function scriptGroomingPipeline(): void
    {
        $callIndex = 0;
        $this->scriptLlm(static function () use (&$callIndex): array {
            $turn = $callIndex % 3;
            ++$callIndex;

            if (0 === $turn) {
                return [
                    'summary' => 'Cartographie existante : la règle RG-001 couvre le plafond de 10 %, mais le cumul des codes promo est absent.',
                    'findings' => [
                        'RG-001 documente le plafond de remise panier à 10 %.',
                        'DiscountPolicy.php porte la logique tarifaire mais ne précise pas les codes promo.',
                    ],
                    'coverage_gaps' => [
                        'Règles de cumul entre codes promotionnels et remises VIP.',
                        'Traçabilité des validations direction.',
                    ],
                    'related_files' => ['src/Pricing/DiscountPolicy.php'],
                ];
            }

            if (1 === $turn) {
                return [
                    'summary' => 'Plusieurs hypothèses implicites sur les cumuls et les seuils de validation exposent à des dérives tarifaires.',
                    'findings' => [
                        'Aucune règle ne tranche le cumul codes promo + remise panier.',
                        'Le format de preuve d’une validation direction n’est pas spécifié.',
                    ],
                    'questions' => [
                        [
                            'question' => 'Un code promo expiré dans un panier sauvegardé doit-il afficher un avertissement dédié ?',
                            'severity' => 'important',
                            'rationale' => 'Évite un rejet silencieux au paiement.',
                        ],
                        [
                            'question' => 'Cumul codes promo : un code promotionnel peut-il se cumuler avec une remise VIP supérieure à 10 % ?',
                            'severity' => 'blocking',
                            'rationale' => 'Impacte directement le calcul du prix TTC et les marges.',
                        ],
                    ],
                ];
            }

            return [
                'readiness_score' => 64,
                'executive_summary' => 'Le socle de remise panier (RG-001) existe mais le cumul des codes promo reste bloquant avant implémentation.',
                'step_summary' => 'Consolidation d’une nouvelle règle sur le non-cumul et d’une décision d’audit.',
                'findings' => [
                    'Lever la question bloquante sur le cumul codes promo / VIP.',
                    'Valider la nouvelle règle candidate sur le non-cumul dans /proposals.',
                ],
                'proposed_rules' => [
                    [
                        'title' => 'Plafond de remise panier',
                        'statement' => 'Doublon exact de RG-001 à ignorer.',
                        'rationale' => 'Déjà présente.',
                        'files' => ['src/Pricing/DiscountPolicy.php'],
                    ],
                    [
                        'title' => 'Non-cumul des codes promotionnels',
                        'statement' => 'Un panier ne peut appliquer qu’un seul code promotionnel et ne se cumule pas avec une remise VIP.',
                        'rationale' => 'Protège la marge et lève l’ambiguïté de RG-001.',
                        'files' => ['src/Pricing/DiscountPolicy.php'],
                    ],
                ],
                'proposed_learnings' => [
                    [
                        'lesson' => 'Les remises sont calculées HT',
                        'type' => 'convention',
                        'evidence' => 'Déjà présent en mémoire.',
                        'reason' => 'Doublon.',
                        'score' => 7,
                        'files' => [],
                        'rules' => [],
                    ],
                    [
                        'lesson' => 'Valider toute remise supérieure à 10 % via un événement d’audit dédié.',
                        'type' => 'decision',
                        'evidence' => 'Recommandation du Critique et du Groomer sur RG-001.',
                        'reason' => 'Assure la traçabilité financière des exceptions direction.',
                        'score' => 8,
                        'files' => ['src/Pricing/DiscountPolicy.php'],
                        'rules' => ['RG-001'],
                    ],
                ],
            ];
        });
    }
}
