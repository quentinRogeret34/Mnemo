<?php

namespace App\Tests\Security;

use App\Entity\ApiKey;
use App\Entity\Connector;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\Confidence;
use App\Enum\ConnectorMode;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use App\Enum\UserStatus;
use App\Security\SafeUrl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $_SERVER['APP_ACCESS_ROLE'] = $_ENV['APP_ACCESS_ROLE'] = 'ROLE_USER';
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, api_key, connector, app_user, user_group RESTART IDENTITY CASCADE');
        foreach (['demo', 'other'] as $slug) {
            $this->entityManager->persist(new Project($slug, ucfirst($slug), '/repos/'.$slug));
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $_SERVER['APP_ACCESS_ROLE'] = $_ENV['APP_ACCESS_ROLE'] = 'PUBLIC_ACCESS';
        parent::tearDown();
    }

    public function testFrontRequiresLoginOnceAccessIsRestricted(): void
    {
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('input[name="_password"]');
        $this->client->request('GET', '/oauth/github/check/connector?code=abc&state=abc');
        self::assertResponseRedirects('/login');
        // GitHub signs its events instead of signing in.
        $this->client->request('POST', '/webhooks/github', content: '{}');
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testApiRequiresAValidKeyLimitedToItsProjects(): void
    {
        $this->client->request('GET', '/api/context?cwd=/repos/demo');
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/settings/keys');
        $crawler = $this->client->submit($crawler->selectButton('Créer la clé')->form(['name' => 'laptop', 'projects' => ['demo']]));
        $token = $crawler->filter('#new-token')->text();
        self::assertStringStartsWith('gk_', $token);
        self::assertSame(ApiKey::hash($token), $this->entityManager->getRepository(ApiKey::class)->findOneBy(['name' => 'laptop'])->getTokenHash());
        $this->client->restart();

        $this->client->request('GET', '/api/context?cwd=/repos/demo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        self::assertSame('demo', json_decode($this->client->getResponse()->getContent(), true)['project']);

        $this->client->request('GET', '/api/context?cwd=/repos/other', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertNull(json_decode($this->client->getResponse()->getContent(), true)['project']);
        $this->client->request('GET', '/api/context?cwd=/repos/new&repository=github.com/acme/new', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertNull(json_decode($this->client->getResponse()->getContent(), true)['project']);
        self::assertSame(2, $this->entityManager->getRepository(Project::class)->count([]));

        $this->client->request('GET', '/api/context?cwd=/repos/demo', server: ['HTTP_AUTHORIZATION' => 'Bearer gk_wrong']);
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/settings/keys');
        $this->client->submit($crawler->selectButton('Révoquer')->form());
        $this->client->restart();
        $this->client->request('GET', '/api/context?cwd=/repos/demo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testApiKeyProjectsCanBeUpdatedAfterCreationAndSwitchedToAllProjects(): void
    {
        $this->entityManager->persist(new Project('third', 'Third', '/repos/third'));
        $this->entityManager->flush();

        $this->client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/settings/keys');
        $crawler = $this->client->submit($crawler->selectButton('Créer la clé')->form(['name' => 'agent', 'projects' => ['demo']]));
        $token = $crawler->filter('#new-token')->text();
        $key = $this->entityManager->getRepository(ApiKey::class)->findOneBy(['name' => 'agent']);
        self::assertNotNull($key);
        $keyId = $key->getId();

        // Add a second project a posteriori
        $form = $crawler->filter('#key-projects-'.$keyId.' form')->selectButton('Enregistrer')->form(['projects' => ['demo', 'other']]);
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/keys');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.keys-table tbody tr', 'Demo, Other');

        $this->client->restart();
        $this->client->request('GET', '/api/context?cwd=/repos/other', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame('other', json_decode((string) $this->client->getResponse()->getContent(), true)['project']);
        $this->client->request('GET', '/api/context?cwd=/repos/third', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertNull(json_decode((string) $this->client->getResponse()->getContent(), true)['project']);

        // Switch the key to "All projects"
        $this->client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/settings/keys');
        $form = $crawler->filter('#key-projects-'.$keyId.' form')->selectButton('Enregistrer')->form(['all_projects' => '1']);
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/keys');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.keys-table tbody tr', 'Tous les projets');

        $this->client->restart();
        $this->client->request('GET', '/api/context?cwd=/repos/third', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame('third', json_decode((string) $this->client->getResponse()->getContent(), true)['project']);

        // Narrow an "All projects" key back down to a single project
        $this->client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/settings/keys');
        $form = $crawler->filter('#key-projects-'.$keyId.' form')->selectButton('Enregistrer')->form(['projects' => [false, 'other']]);
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/keys');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.keys-table tbody tr', 'Other');

        $this->client->restart();
        $this->client->request('GET', '/api/context?cwd=/repos/demo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertNull(json_decode((string) $this->client->getResponse()->getContent(), true)['project']);
        $this->client->request('GET', '/api/context?cwd=/repos/other', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame('other', json_decode((string) $this->client->getResponse()->getContent(), true)['project']);

        // A scoped user cannot edit someone else's key nor attach an out-of-scope project to their own key
        $bob = $this->member('bob', 'demo');
        [$bobKey] = ApiKey::generate('bob-key');
        $bobKey->setOwner($bob);
        $bobKey->addProject($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo']));
        $this->entityManager->persist($bobKey);
        $this->entityManager->flush();
        $bobKeyId = $bobKey->getId();
        $this->entityManager->clear();

        $this->client->loginUser($this->entityManager->find(User::class, $bob->getId()));
        $crawler = $this->client->request('GET', '/settings/keys');
        $bobCsrf = (string) $crawler->filter('#key-projects-'.$bobKeyId.' input[name="_token"]')->attr('value');

        // Cannot touch admin's key
        $this->client->request('POST', '/settings/keys/'.$keyId.'/projects', ['_token' => $bobCsrf, 'all_projects' => '1']);
        self::assertResponseStatusCodeSame(404);

        // Submitting an out-of-scope project slug does not attach it
        $this->client->request('POST', '/settings/keys/'.$bobKeyId.'/projects', ['_token' => $bobCsrf, 'projects' => ['demo', 'other']]);
        self::assertResponseRedirects('/settings/keys');
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(ApiKey::class, $bobKeyId);
        self::assertSame(['demo'], $reloaded->getProjects()->map(static fn (Project $p): string => $p->getSlug())->getValues());

        // A revoked key cannot have its projects modified
        $reloaded->revoke();
        $this->entityManager->flush();
        $this->client->request('POST', '/settings/keys/'.$bobKeyId.'/projects', ['_token' => $bobCsrf, 'all_projects' => '1']);
        self::assertResponseRedirects('/settings/keys');
        $this->entityManager->clear();
        self::assertSame(['demo'], $this->entityManager->find(ApiKey::class, $bobKeyId)->getProjects()->map(static fn (Project $p): string => $p->getSlug())->getValues());
    }

    public function testAUserLimitedToSomeProjectsReachesNoConnector(): void
    {
        $connector = new Connector();
        $connector->setName('Forge');
        $connector->setMode(ConnectorMode::GitHub);
        $connector->setEndpoint('https://api.github.com');
        $connector->setArguments(['repository' => 'acme/shop']);
        $connector->setProject($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'other']));
        $this->entityManager->persist($connector);
        $bob = $this->member('bob', 'demo');
        $this->entityManager->clear();
        $this->client->loginUser($this->entityManager->find(User::class, $bob->getId()));
        $id = $connector->getId();

        foreach ([['GET', '/connectors/'.$id.'/pull-requests'], ['POST', '/connectors/'.$id.'/pull-requests/12/review'], ['POST', '/connectors/'.$id.'/review-model'], ['POST', '/connectors/'.$id.'/authorize'], ['POST', '/connectors/'.$id.'/authorize/revoke'], ['GET', '/connectors/authorize/check']] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testTheKeysOfADisabledUserStopWorking(): void
    {
        $bob = $this->member('bob', 'demo');
        [$key, $token] = ApiKey::generate('laptop');
        $key->setOwner($bob);
        $this->entityManager->persist($key);
        $this->entityManager->flush();
        $this->client->request('GET', '/api/context?cwd=/repos/demo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        $bob->setStatus(UserStatus::Disabled);
        $this->entityManager->flush();
        $this->client->request('GET', '/api/context?cwd=/repos/demo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(401);

        $admin = new User('admin@example.com', 'Admin', 'github', 'admin');
        $admin->setStatus(UserStatus::Active);
        $admin->setSuperAdmin(true);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('form[action="/admin/users/'.$bob->getId().'"]')->form(['status' => UserStatus::Disabled->value]));
        self::assertResponseRedirects('/admin/users');
        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(ApiKey::class, $key->getId())->isRevoked());
    }

    public function testScopedUserCannotLeakOrDeleteSharedMemoryFromOtherProject(): void
    {
        $demo = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo']);
        $other = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'other']);
        $secretFile = new Node($other, NodeType::CodeFile, 'src/secret.ts', 'secret.ts', 'src/secret.ts');
        $shared = new Node(null, NodeType::Memory, 'mem-shared', 'Convention partagée entre deux projets.', metadata: ['type' => 'convention', 'source' => 'manual']);
        $shared->addProject($demo);
        $shared->addProject($other);
        $this->entityManager->persist($secretFile);
        $this->entityManager->persist($shared);
        $this->entityManager->persist(new Edge($shared, $secretFile, 'about', Confidence::Extracted, EdgeOrigin::Human));
        $this->entityManager->flush();

        $bob = $this->member('bob', 'demo');
        $this->entityManager->clear();
        $this->client->loginUser($this->entityManager->find(User::class, $bob->getId()));

        $this->client->request('GET', '/memories/'.$shared->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('src/secret.ts', $html);
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $this->client->getResponse()->headers->get('X-Frame-Options'));

        $this->client->request('GET', '/memories/graph/data?project=demo');
        $graph = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $labels = array_column($graph['nodes'], 'label');
        self::assertNotContains('Other', $labels);
        self::assertNotContains('src/secret.ts', $labels);

        $csrf = $this->client->request('GET', '/memories/'.$shared->getId())->filter('form[action="/memories/'.$shared->getId().'/delete"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/memories/'.$shared->getId().'/delete', ['_token' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Node::class, $shared->getId()));
    }

    public function testSafeUrlBlocksPrivateAndNonHttpsTargets(): void
    {
        foreach ([
            'http://example.com/mcp',
            'https://localhost/mcp',
            'https://127.0.0.1/mcp',
            'https://169.254.169.254/latest/meta-data/',
            'https://10.0.0.1/mcp',
            'https://service.internal/mcp',
        ] as $url) {
            try {
                SafeUrl::requireHttps($url);
                self::fail('Expected SafeUrl::requireHttps() to reject '.$url);
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertNull(SafeUrl::http('javascript:alert(1)'));
        self::assertNull(SafeUrl::http('data:text/html,<script>alert(1)</script>'));
        self::assertSame('https://github.com/acme/shop', SafeUrl::http('https://github.com/acme/shop'));
    }

    private function member(string $name, string $slug): User
    {
        $user = new User($name.'@example.com', ucfirst($name), 'github', $name);
        $user->setStatus(UserStatus::Active);
        $group = new UserGroup(ucfirst($slug));
        $group->setUsers([$user]);
        $group->setProjects([$this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug])]);
        $this->entityManager->persist($user);
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $user;
    }
}
