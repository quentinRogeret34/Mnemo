<?php

namespace App\Tests\Review;

use App\Entity\Connector;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\ConnectorMode;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Forge\GitHubApp;
use App\Ingest\GraphifyImporter;
use App\Memory\MemoryStore;
use App\Review\PullRequestReviewer;
use App\Review\ReviewAdvisor;
use App\Review\ReviewKnowledge;
use App\Tests\LlmTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PullRequestReviewerTest extends KernelTestCase
{
    use LlmTestTrait;

    private EntityManagerInterface $entityManager;
    private Connector $connector;
    private Node $convention;
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> method, path, JSON body */
    private array $writes = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($project, __DIR__.'/../Fixtures/graphify/graph-v1.json');

        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/domain/catalog.ts']);
        $rule = new Node($project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, [
            'statement' => 'Une remise ne dépasse jamais 10 %.',
            'status' => 'validated',
            'shadow_areas' => [['category' => 'edge_case', 'gap' => 'Cumul non précisé.', 'question' => 'Deux remises se cumulent-elles ?', 'severity' => 'major']],
        ]);
        $this->entityManager->persist($rule);
        $this->entityManager->persist(new Edge($rule, $file, 'implemented_by', Confidence::Verified, EdgeOrigin::Human));

        $store = self::getContainer()->get(MemoryStore::class);
        $this->convention = $store->add('Les remises passent par catalog.ts, écrire à @alice avant de toucher au plafond.', MemoryType::Convention, 'manual');
        $private = $store->add('Note privée sur le catalogue.', MemoryType::Fact, 'manual', ['visibility' => 'private', 'owner_id' => 1]);
        foreach ([$this->convention, $private] as $memory) {
            $memory->addProject($project);
            $store->link($memory, $file);
        }

        $this->connector = new Connector();
        $this->connector->setName('acme/shop');
        $this->connector->setMode(ConnectorMode::GitHub);
        $this->connector->setEndpoint('https://api.github.com');
        $this->connector->setArguments(['repository' => 'acme/shop', 'review' => true]);
        $this->connector->setProject($project);
        $this->entityManager->persist($this->connector);
        $this->entityManager->flush();
    }

    public function testTheCommentTellsWhatTheGraphKnowsAndTheCheckWarnsAboutAnUnguardedRule(): void
    {
        $this->scriptLlm(fn (): array => ['remarks' => [
            ['source' => 'M'.$this->convention->getId(), 'path' => 'src/domain/catalog.ts', 'line' => 1, 'remark' => 'Le plafond passe à 20 % sans prévenir.', 'severity' => 'major'],
            ['source' => 'RG-042', 'path' => 'src/domain/catalog.ts', 'line' => 99, 'remark' => 'Ligne hors du diff.', 'severity' => 'minor'],
            ['source' => 'M999', 'path' => 'src/domain/catalog.ts', 'remark' => 'Source inventée.', 'severity' => 'blocker'],
            ['source' => 'RG-042', 'path' => 'README.md', 'remark' => 'Fichier hors du diff.', 'severity' => 'blocker'],
        ]]);

        $body = $this->reviewer()->review($this->connector, 12);

        self::assertStringContainsString('[RG-042](http://localhost:8080/projects/demo/rules/RG-042) Remise plafonnée** (validée) dans `src/domain/catalog.ts`', $body);
        self::assertStringContainsString('**À vérifier** : règle validée touchée sans test', $body);
        self::assertStringContainsString('Zone d\'ombre (majeur) : Deux remises se cumulent-elles ?', $body);
        self::assertStringContainsString('**majeur** · `src/domain/catalog.ts:1` : Le plafond passe à 20 % sans prévenir. (source : [mémoire #'.$this->convention->getId().']', $body);
        self::assertStringContainsString('**mineur** · `src/domain/catalog.ts` : Ligne hors du diff.', $body);
        self::assertStringContainsString('Convention · `src/domain/catalog.ts` : Les remises passent par catalog.ts, écrire à &#64;alice', $body);
        self::assertStringNotContainsString('Source inventée', $body);
        self::assertStringNotContainsString('Fichier hors du diff', $body);
        self::assertStringNotContainsString('Note privée', $body);
        self::assertStringNotContainsString('Note privée', $this->llmCalls[0]['user']);
        self::assertStringContainsString("      -const MAX = 0.1\n    1 +const MAX = 0.2", $this->llmCalls[0]['user']);
        self::assertSame('test-model', $this->llmCalls[0]['model']);

        self::assertSame(['POST', '/repos/acme/shop/issues/12/comments', ['body' => $body]], $this->writes[0]);
        [$method, $path, $check] = $this->writes[1];
        self::assertSame(['POST', '/repos/acme/shop/check-runs'], [$method, $path]);
        self::assertSame([PullRequestReviewer::CHECK, 'head-sha', 'neutral'], [$check['name'], $check['head_sha'], $check['conclusion']]);
        self::assertStringContainsString('**RG-042** Remise plafonnée', $check['output']['summary']);

        [$method, $path, $review] = $this->writes[2];
        self::assertSame(['POST', '/repos/acme/shop/pulls/12/reviews', 'head-sha', 'COMMENT'], [$method, $path, $review['commit_id'], $review['event']]);
        self::assertSame([['path' => 'src/domain/catalog.ts', 'line' => 1, 'side' => 'RIGHT']], array_map(static fn (array $comment): array => array_diff_key($comment, ['body' => true]), $review['comments']));
        self::assertStringContainsString('**Majeur** : Le plafond passe à 20 % sans prévenir.', $review['comments'][0]['body']);
        self::assertCount(3, $this->writes);
    }

    public function testThePreviousLineCommentsGoAwayUnlessSomeoneAnsweredThem(): void
    {
        $this->scriptLlm(fn (): array => ['remarks' => [
            ['source' => 'M'.$this->convention->getId(), 'path' => 'src/domain/catalog.ts', 'line' => 1, 'remark' => 'Le plafond passe à 20 % sans prévenir.', 'severity' => 'major'],
        ]]);
        $answered = "<!-- graphe-review-line -->\n**Majeur** : Le plafond passe à 20 % sans prévenir.\n\nSource : [mémoire #".$this->convention->getId().'](http://localhost:8080/memories/'.$this->convention->getId().')';

        $this->reviewer(reviewComments: [
            ['id' => 21, 'path' => 'src/domain/catalog.ts', 'body' => "<!-- graphe-review-line -->\nancienne remarque", 'user' => ['type' => 'Bot']],
            ['id' => 22, 'path' => 'src/domain/catalog.ts', 'body' => $answered, 'user' => ['type' => 'Bot']],
            ['id' => 23, 'path' => 'src/domain/catalog.ts', 'body' => 'Voulu, voir le ticket.', 'user' => ['type' => 'User'], 'in_reply_to_id' => 22],
            ['id' => 24, 'path' => 'src/domain/catalog.ts', 'body' => '<!-- graphe-review-line --> écrit par un humain', 'user' => ['type' => 'User']],
        ])->review($this->connector, 12);

        self::assertSame([['POST', '/repos/acme/shop/issues/12/comments'], ['POST', '/repos/acme/shop/check-runs'], ['DELETE', '/repos/acme/shop/pulls/comments/21']], array_map(static fn (array $write): array => \array_slice($write, 0, 2), $this->writes));
    }

    public function testTheCommentIsRewrittenAndTheCheckFailsWhenTheConnectorAsksForIt(): void
    {
        $this->forbidLlm();
        $this->connector->setArguments(['review_llm' => false, 'review_check' => 'fail'] + $this->connector->getArguments());

        $this->reviewer(comments: [
            ['id' => 5, 'body' => '<!-- graphe-review --> written by someone else', 'user' => ['type' => 'User']],
            ['id' => 8, 'body' => "<!-- graphe-review -->\nold", 'user' => ['type' => 'Bot']],
        ])->review($this->connector, 12);

        self::assertSame(['PATCH', '/repos/acme/shop/issues/comments/8'], \array_slice($this->writes[0], 0, 2));
        self::assertSame('failure', $this->writes[1][2]['conclusion']);
    }

    public function testARuleNamedByThePullRequestOrCoveredByATestPassesTheCheck(): void
    {
        $this->connector->setArguments(['review_llm' => false] + $this->connector->getArguments());

        $this->reviewer(commits: [['commit' => ['message' => 'Relève le plafond (RG-042)']]])->review($this->connector, 12);
        self::assertSame('success', $this->writes[1][2]['conclusion']);

        $this->writes = [];
        $body = $this->reviewer(files: [['filename' => 'src/domain/catalog.ts', 'patch' => '+x'], ['filename' => 'tests/catalog.test.ts', 'patch' => '+y']])->review($this->connector, 12);
        self::assertSame('success', $this->writes[1][2]['conclusion']);
        self::assertStringNotContainsString('À vérifier', $body);
    }

    public function testAPullRequestTheGraphKnowsNothingAboutGetsACheckButNoComment(): void
    {
        $this->forbidLlm();

        $body = $this->reviewer(files: [['filename' => 'docs/unknown.md', 'patch' => '+hello']])->review($this->connector, 12);

        self::assertStringContainsString('Rien de connu dans le graphe', $body);
        self::assertSame([['POST', '/repos/acme/shop/check-runs', 'success']], array_map(static fn (array $write): array => [$write[0], $write[1], $write[2]['conclusion']], $this->writes));
    }

    public function testTheConnectorChoosesTheModelThatReadsTheDiff(): void
    {
        $this->scriptLlm(static fn (): array => ['remarks' => []]);
        $this->connector->setArguments(['review_model' => 'anthropic/claude-opus-4.1'] + $this->connector->getArguments());

        $this->reviewer()->review($this->connector, 12, false);

        self::assertSame('anthropic/claude-opus-4.1', $this->llmCalls[0]['model']);
    }

    public function testADryRunPublishesNothing(): void
    {
        $this->connector->setArguments(['review_llm' => false] + $this->connector->getArguments());

        self::assertStringContainsString('RG-042', $this->reviewer()->review($this->connector, 12, false));
        self::assertSame([], $this->writes);
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param list<array<string, mixed>> $commits
     * @param list<array<string, mixed>> $comments
     * @param list<array<string, mixed>> $reviewComments
     */
    private function reviewer(array $files = [['filename' => 'src/domain/catalog.ts', 'patch' => "@@ -1 +1 @@\n-const MAX = 0.1\n+const MAX = 0.2"]], array $commits = [], array $comments = [], array $reviewComments = []): PullRequestReviewer
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($files, $commits, $comments, $reviewComments): JsonMockResponse {
            $path = parse_url($url, \PHP_URL_PATH);
            if ('GET' !== $method && !str_contains($path, 'access_tokens')) {
                $this->writes[] = [$method, $path, json_decode($options['body'] ?? '', true)];

                return new JsonMockResponse(['id' => 1]);
            }

            return new JsonMockResponse(match ($path) {
                '/repos/acme/shop/installation' => ['id' => 7],
                '/app/installations/7/access_tokens' => ['token' => 'installation-token'],
                '/repos/acme/shop/pulls/12' => ['title' => 'Relève le plafond', 'body' => 'Demande du commerce.', 'head' => ['sha' => 'head-sha']],
                '/repos/acme/shop/pulls/12/files' => $files,
                '/repos/acme/shop/pulls/12/commits' => $commits,
                '/repos/acme/shop/issues/12/comments' => $comments,
                '/repos/acme/shop/pulls/12/comments' => $reviewComments,
            });
        });
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]), $pem);

        return new PullRequestReviewer(
            new GitHubApp($http, new ArrayAdapter(), '42', $pem, 'webhook-secret', ''),
            self::getContainer()->get(ReviewKnowledge::class),
            self::getContainer()->get(ReviewAdvisor::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
        );
    }
}
