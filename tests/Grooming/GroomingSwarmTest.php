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
use App\Message\RunGroomingSwarm;
use App\MessageHandler\RunGroomingSwarmHandler;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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

        $transport = self::getContainer()->get('messenger.transport.async');
        if ($transport instanceof InMemoryTransport) {
            $transport->reset();
        }

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

    public function testAsyncLaunchOpensProgressModalRunsMultiRoundDebateAndFiltersProposals(): void
    {
        $this->scriptGroomingDebates([2, 1]);

        // Seed an unrelated pending proposal on the same project to verify ?grooming=<id> isolates only this run's proposals.
        $unrelated = new Proposal($this->project, ProposalKind::Learning, [
            'lesson' => 'Mémoire sans rapport avec le grooming.',
            'evidence' => 'manual',
            'type' => 'fact',
            'score' => 6,
            'axes' => ['durability' => 3, 'reuse' => 3, 'project_fit' => 3, 'risk' => 3],
            'files' => [],
            'rules' => [],
            'adr' => [],
            'reconciliation' => 'new',
            'target_id' => null,
            'target_text' => null,
            'reason' => '',
            'source' => ['kind' => 'conversation', 'label' => 'unrelated'],
        ]);
        $this->entityManager->persist($unrelated);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/projects/demo/grooming');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.grooming-chips', 'Plafond de remise panier');
        self::assertSelectorExists('.grooming-form select[name="rounds"]');

        $form = $crawler->filter('.grooming-form')->form([
            'topic' => 'Remises et promotions',
            'context' => 'Préparation sprint Q4 : cumul codes promo et remises VIP.',
            'rounds' => '2',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/projects/demo/grooming/\d+\?started=1$#', $location);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(RunGroomingSwarm::class, $message);
        self::assertCount(0, $this->llmCalls);

        // Verify the HTTP request returned immediately with the progress modal open.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('dialog#grooming-running-modal[open]');
        self::assertSelectorTextContains('#grooming-running-modal', 'Ruche de grooming en cours');

        // Status JSON endpoint reports running state before worker executes.
        $this->client->request('GET', '/projects/demo/grooming/'.$message->runId.'/status');
        self::assertResponseIsSuccessful();
        $runningJson = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(GroomingRun::STATUS_RUNNING, $runningJson['status']);
        self::assertSame(2, $runningJson['rounds']);
        self::assertSame(7, $runningJson['expected_steps']);

        // Execute the async Messenger handler (2 rounds * 3 debating agents + 1 Groomer synthesis = 7 LLM calls).
        $this->executeGrooming($message);

        self::assertCount(7, $this->llmCalls);
        // Round 1 Product sees graph context; Round 2 Product sees Round 1 Architect & Critique transcript.
        self::assertStringContainsString('Plafond de remise panier', $this->llmCalls[0]['user']);
        self::assertStringContainsString('src/Pricing/DiscountPolicy.php', $this->llmCalls[0]['user']);
        self::assertStringContainsString('Tour 1/2', $this->llmCalls[0]['user']);
        self::assertStringContainsString('Tour 2/2 (affinage)', $this->llmCalls[3]['user']);
        self::assertStringContainsString('Fil de discussion précédent', $this->llmCalls[3]['user']);
        self::assertStringContainsString('Architecte Tech', $this->llmCalls[3]['user']);
        self::assertStringContainsString('Critique & QA', $this->llmCalls[3]['user']);
        // Groomer receives full multi-round debate transcript.
        self::assertStringContainsString('Transcription complète du débat multi-tours', $this->llmCalls[6]['user']);

        // Status JSON endpoint now reports completed.
        $this->client->request('GET', '/projects/demo/grooming/'.$message->runId.'/status');
        self::assertResponseIsSuccessful();
        $doneJson = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(GroomingRun::STATUS_COMPLETED, $doneJson['status']);
        self::assertSame(7, $doneJson['steps_count']);
        self::assertSame(64, $doneJson['readiness_score']);
        self::assertSame(2, $doneJson['proposals_count']);

        // Report page displays the 2 debate rounds + synthesis, questions, and filtered /proposals link.
        $crawler = $this->client->request('GET', '/projects/demo/grooming/'.$message->runId);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#grooming-running-modal');
        self::assertSelectorTextContains('.grooming-ring__value', '64%');
        self::assertCount(7, $crawler->filter('.grooming-agent-card'));
        self::assertCount(3, $crawler->filter('.grooming-round__head'));
        self::assertCount(2, $crawler->filter('.grooming-question'));
        self::assertSelectorTextContains('.grooming-question:first-child', 'Bloquant');
        self::assertCount(2, $crawler->filter('.grooming-proposal-item'));

        $openProposalsHref = (string) $crawler->selectLink('Ouvrir /proposals')->attr('href');
        self::assertStringContainsString('grooming='.$message->runId, $openProposalsHref);

        // Clicking "Ouvrir /proposals" displays ONLY the 2 proposals linked to this grooming run (excluding the unrelated one).
        $filteredCrawler = $this->client->request('GET', $openProposalsHref);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $filteredCrawler->filter('.proposal-row'));
        self::assertSelectorTextContains('.filter-chips', 'Remises et promotions');
        self::assertStringNotContainsString('Mémoire sans rapport', $filteredCrawler->html());

        // Unfiltered /proposals shows all 3 proposals on the project.
        $allCrawler = $this->client->request('GET', '/proposals?project=demo');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $allCrawler->filter('.proposal-row'));

        // A second 1-round run deduplicates against already-pending proposals.
        $this->llmCalls = [];
        $transport->reset();

        $crawler = $this->client->request('GET', '/projects/demo/grooming');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.grooming-run-row'));

        $this->client->submit($crawler->filter('.grooming-form')->form([
            'topic' => 'Remises et promotions',
            'rounds' => '1',
        ]));
        self::assertResponseRedirects();
        $secondMessage = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(RunGroomingSwarm::class, $secondMessage);
        $this->executeGrooming($secondMessage);

        self::assertCount(4, $this->llmCalls);
        $this->entityManager->clear();
        self::assertCount(2, $this->entityManager->getRepository(GroomingRun::class)->findAll());
        self::assertCount(3, $this->entityManager->getRepository(Proposal::class)->findAll());
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

        $this->client->request('GET', '/projects/demo/grooming/'.$otherRunId);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/projects/demo/grooming/'.$otherRunId.'/status');
        self::assertResponseStatusCodeSame(404);

        // Proposals filtered by another project's grooming run must also be 404 (both directly and after scope redirect).
        $this->client->request('GET', '/proposals?project=demo&grooming='.$otherRunId);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/proposals?grooming='.$otherRunId);
        self::assertResponseRedirects('/proposals?project=demo&grooming='.$otherRunId);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(404);

        // Invalid CSRF token on allowed project is rejected.
        $this->client->request('POST', '/projects/demo/grooming', ['topic' => 'Remises', '_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testHighSignalLearningFromGroomingIsAutoApplied(): void
    {
        $call = 0;
        $this->scriptLlm(static function () use (&$call): array {
            ++$call;
            if (1 === $call) {
                return ['stance' => 'OK', 'summary' => 'Produit', 'findings' => ['F1']];
            }
            if (2 === $call) {
                return ['stance' => 'OK', 'summary' => 'Archi', 'findings' => ['F2']];
            }
            if (3 === $call) {
                return ['stance' => 'OK', 'summary' => 'Critique', 'findings' => ['F3'], 'questions' => []];
            }

            return [
                'readiness_score' => 92,
                'executive_summary' => 'Sujet clair et validé.',
                'step_summary' => 'Synthèse prête.',
                'findings' => ['Tout est aligné.'],
                'proposed_rules' => [],
                'proposed_learnings' => [
                    [
                        'lesson' => 'Toujours journaliser les remises directionnelles.',
                        'type' => 'decision',
                        'evidence' => 'Consensus unanime.',
                        'reason' => 'Audit légal.',
                        'score' => 10,
                        'files' => ['src/Pricing/DiscountPolicy.php'],
                        'rules' => ['RG-001'],
                    ],
                    [
                        'lesson' => 'Signal à 9 reste en attente de validation.',
                        'type' => 'fact',
                        'evidence' => 'Débat.',
                        'reason' => 'Seuil exact.',
                        'score' => 9,
                        'files' => [],
                        'rules' => [],
                    ],
                ],
            ];
        });

        $run = self::getContainer()->get(\App\Grooming\GroomingSwarmRunner::class)->run($this->project, 'Remises VIP', rounds: 1);

        $this->entityManager->clear();
        $proposals = $this->entityManager->getRepository(Proposal::class)->findBy(['id' => $run->run->getCreatedProposalIds()], ['id' => 'ASC']);
        self::assertCount(2, $proposals);
        self::assertSame(ProposalStatus::Applied, $proposals[0]->getStatus());
        self::assertTrue($proposals[0]->getPayload()['auto_applied'] ?? false);
        self::assertSame(ProposalStatus::Pending, $proposals[1]->getStatus());
        self::assertNotNull($this->entityManager->getRepository(Node::class)->findOneBy([
            'type' => NodeType::Memory,
            'label' => 'Toujours journaliser les remises directionnelles.',
        ]));
    }

    private function executeGrooming(RunGroomingSwarm $message): void
    {
        self::getContainer()->get(RunGroomingSwarmHandler::class)($message);
    }

    /**
     * @param list<int> $roundsSequence
     */
    private function scriptGroomingDebates(array $roundsSequence): void
    {
        $runIndex = 0;
        $callInRun = 0;

        $this->scriptLlm(static function () use (&$runIndex, &$callInRun, $roundsSequence): array {
            $rounds = $roundsSequence[$runIndex] ?? 1;
            $totalDebateCalls = $rounds * 3;
            $index = $callInRun;
            ++$callInRun;

            if ($index < $totalDebateCalls) {
                $slot = $index % 3;
                $round = intdiv($index, 3) + 1;

                if (0 === $slot) {
                    return [
                        'stance' => 1 === $round ? 'Périmètre incomplet' : 'Arbitrage proposé',
                        'summary' => 1 === $round
                            ? 'Position Produit T1 : RG-001 couvre le plafond de 10 %, mais le cumul codes promo reste flou.'
                            : 'Réponse Produit T2 : accord avec l’Architecte et le Critique pour interdire le cumul par défaut.',
                        'findings' => [
                            'RG-001 documente le plafond de remise panier à 10 %.',
                            'Les codes promotionnels doivent avoir une règle de non-cumul explicite.',
                        ],
                    ];
                }

                if (1 === $slot) {
                    return [
                        'stance' => 1 === $round ? 'Impact code identifié' : 'Garde-fou validé',
                        'summary' => 1 === $round
                            ? 'Position Architecte T1 : DiscountPolicy.php calcule la remise sans vérification de cumul.'
                            : 'Réponse Architecte T2 : un événement d’audit dédié permet de tracer toute exception direction.',
                        'findings' => [
                            'DiscountPolicy.php porte la logique tarifaire à sécuriser.',
                            'Prévoir un événement d’audit sur les dépassements > 10 %.',
                        ],
                    ];
                }

                return [
                    'stance' => 1 === $round ? 'Zones d’ombre détectées' : 'Bloquant confirmé',
                    'summary' => 1 === $round
                        ? 'Position Critique T1 : risque de dérive tarifaire sur les cumuls VIP et codes expirés.'
                        : 'Réponse Critique T2 : la proposition Produit/Tech réduit le risque, mais le cas VIP reste bloquant.',
                    'findings' => [
                        'Aucune règle ne tranche encore le cumul codes promo + remise VIP.',
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

            ++$runIndex;
            $callInRun = 0;

            return [
                'readiness_score' => 64,
                'executive_summary' => 'Après débat entre Produit, Architecte et Critique, le non-cumul fait consensus mais le cas VIP reste bloquant.',
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
                        'evidence' => 'Consensus du débat multi-tours Produit/Architecte/Critique.',
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
