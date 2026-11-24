<?php

namespace App\Tests\Security;

use App\Entity\ApiKey;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\NodeType;
use App\Enum\UserStatus;
use App\Security\OAuth\OAuthClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class AccountsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $demo;
    private Project $other;

    protected function setUp(): void
    {
        $_SERVER['APP_ACCESS_ROLE'] = $_ENV['APP_ACCESS_ROLE'] = 'ROLE_USER';
        $_SERVER['APP_ADMIN_EMAILS'] = $_ENV['APP_ADMIN_EMAILS'] = 'someone@example.com, Alice@Example.com';
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, api_key, app_user, user_group RESTART IDENTITY CASCADE');
        $this->demo = new Project('demo', 'Demo project', '/repos/demo');
        $this->other = new Project('other', 'Other project', '/repos/other');
        $this->entityManager->persist($this->demo);
        $this->entityManager->persist($this->other);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $_SERVER['APP_ACCESS_ROLE'] = $_ENV['APP_ACCESS_ROLE'] = 'PUBLIC_ACCESS';
        $_SERVER['APP_ADMIN_EMAILS'] = $_ENV['APP_ADMIN_EMAILS'] = '';
        parent::tearDown();
    }

    public function testAListedEmailSignsInAsSuperAdminAndTheOthersWaitForApproval(): void
    {
        $account = ['id' => 10, 'login' => 'mallory', 'name' => 'Mallory', 'email' => 'mallory@example.com'];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$account): JsonMockResponse {
            return new JsonMockResponse(match (true) {
                str_contains($url, 'access_token') => ['access_token' => 'token'],
                str_ends_with($url, '/user/emails') => [['email' => 'old@example.com', 'primary' => false, 'verified' => true], ['email' => $account['email'], 'primary' => true, 'verified' => true]],
                default => $account,
            });
        });
        self::getContainer()->set(OAuthClient::class, new OAuthClient($http, 'client-id', 'client-secret', '', '', 'https://gitlab.com', '', ''));

        $crawler = $this->client->request('GET', '/login');
        self::assertSame(['Continuer avec GitHub'], $crawler->filter('.login__provider')->each(static fn ($link): string => $link->text()));

        $this->signIn();
        $this->client->followRedirect();
        self::assertResponseRedirects('/pending');
        self::assertFalse($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'mallory@example.com'])->isSuperAdmin());
        $this->client->followRedirect();
        $this->logOut();

        $account = ['id' => 11, 'login' => 'alice', 'name' => 'Alice', 'email' => 'alice@example.com'];
        $this->signIn();
        self::assertResponseRedirects('/dashboard');
        $alice = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'alice@example.com']);
        self::assertTrue($alice->isSuperAdmin());
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        $this->logOut();

        $account = ['id' => 12, 'login' => 'bob', 'name' => 'Bob', 'email' => 'bob@example.com'];
        $this->signIn();
        $this->client->followRedirect();
        self::assertResponseRedirects('/pending');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.login__card', 'bob@example.com');
        self::assertSame(UserStatus::Pending, $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'bob@example.com'])->getStatus());
        $this->logOut();

        $this->client->request('GET', '/oauth/github');
        $this->client->request('GET', '/oauth/github/check?code=abc&state=forged');
        self::assertResponseRedirects('/login');
        self::assertSelectorTextContains('.flash--error', 'La connexion a expiré', $this->client->followRedirect()->html());
        self::assertSame(3, $this->entityManager->getRepository(User::class)->count());
    }

    public function testSuperAdminActivatesAccountsAndGroupsOpenProjects(): void
    {
        $admin = $this->user('admin@example.com', 'Admin', superAdmin: true);
        $bob = $this->user('bob@example.com', 'Bob', UserStatus::Pending);

        $this->client->loginUser($bob);
        $this->client->request('GET', '/admin/users');
        self::assertResponseRedirects('/pending');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->selectButton('Créer le groupe')->form(['name' => 'Back']));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[action="/admin/groups/1"]')->selectButton('Enregistrer')->form(['projects' => ['demo']]));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[action="/admin/users/'.$bob->getId().'"]')->selectButton('Enregistrer')->form(['status' => 'active', 'groups' => ['1']]));

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[action="/admin/users/'.$admin->getId().'"]')->selectButton('Enregistrer')->form(['status' => 'disabled']));
        self::assertSelectorTextContains('.flash--error', 'ne se retire pas lui-même', $this->client->followRedirect()->html());

        $this->entityManager->clear();
        $this->client->loginUser($this->entityManager->find(User::class, $bob->getId()));
        $crawler = $this->client->request('GET', '/');
        self::assertSame(['Demo projectdemo'], $crawler->filter('.content .project-row__title')->each(static fn ($title): string => $title->text()));
        $this->client->request('GET', '/projects/other');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/memories');
        self::assertResponseRedirects('/memories?project=demo');
        $this->client->request('GET', '/memories?project=other');
        self::assertResponseStatusCodeSame(404);
        foreach (['/connectors', '/documents', '/admin/users'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testPrivateAndGroupMemoriesStayWithTheirAudience(): void
    {
        $alice = $this->user('alice@example.com', 'Alice');
        $bob = $this->user('bob@example.com', 'Bob');
        $carol = $this->user('carol@example.com', 'Carol');
        $everyone = new UserGroup('Tous');
        $everyone->setUsers([$alice, $bob, $carol]);
        $everyone->setProjects([$this->demo]);
        $back = new UserGroup('Back');
        $back->setUsers([$alice, $bob]);
        $this->entityManager->persist($everyone);
        $this->entityManager->persist($back);
        $this->entityManager->flush();
        $this->entityManager->clear();
        [$alice, $bob, $carol] = array_map(fn (User $user): User => $this->entityManager->find(User::class, $user->getId()), [$alice, $bob, $carol]);

        $this->client->loginUser($alice);
        foreach (['project' => 'Convention partagée.', 'group:'.$back->getId() => 'Astuce du back.', 'private' => 'Alice préfère les réponses courtes.'] as $visibility => $text) {
            $crawler = $this->client->request('GET', '/memories?project=demo');
            $this->client->submit($crawler->selectButton('Ajouter la mémoire')->form(['memory_form[text]' => $text, 'memory_form[visibility]' => $visibility]));
            self::assertResponseRedirects();
        }
        $private = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::Memory, 'label' => 'Alice préfère les réponses courtes.']);
        self::assertSame(['owner_id' => $alice->getId(), 'visibility' => 'private'], array_intersect_key($private->getMetadata(), ['owner_id' => 1, 'visibility' => 1]));

        $seen = function (User $user): array {
            $this->client->loginUser($user);
            $texts = $this->client->request('GET', '/memories?project=demo')->filter('.content .row--memory .memory-text')->each(static fn ($text): string => $text->text());
            sort($texts);

            return $texts;
        };
        self::assertSame(['Alice préfère les réponses courtes.', 'Astuce du back.', 'Convention partagée.'], $seen($alice));
        self::assertSame(['Astuce du back.', 'Convention partagée.'], $seen($bob));
        self::assertSame(['Convention partagée.'], $seen($carol));

        $this->client->request('GET', '/memories/'.$private->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/memories/graph/data?project=demo');
        self::assertCount(1, array_filter(json_decode($this->client->getResponse()->getContent(), true)['nodes'], static fn (array $node): bool => 'memory' === $node['kind']));

        [$key, $token] = ApiKey::generate('laptop de Bob');
        $key->setOwner($this->entityManager->find(User::class, $bob->getId()));
        [$orphanKey, $orphanToken] = ApiKey::generate('ancienne clé');
        $this->entityManager->persist($key);
        $this->entityManager->persist($orphanKey);
        $this->entityManager->flush();
        $this->client->restart();
        $context = function (string $bearer): string {
            $this->client->request('GET', '/api/context?project=demo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]);

            return json_decode((string) $this->client->getResponse()->getContent(), true)['context'];
        };
        self::assertStringContainsString('Astuce du back.', $context($token));
        self::assertStringNotContainsString('Alice préfère', $context($token));
        self::assertStringNotContainsString('Astuce du back.', $context($orphanToken));
        self::assertStringContainsString('Convention partagée.', $context($orphanToken));
    }

    public function testAKeyCreatesAnUnknownProjectInTheGroupsOfItsOwner(): void
    {
        $bob = $this->user('bob@example.com', 'Bob');
        $carol = $this->user('carol@example.com', 'Carol');
        $back = new UserGroup('Back');
        $back->setUsers([$bob]);
        $this->entityManager->persist($back);
        $tokens = [];
        foreach (['bob' => $bob, 'carol' => $carol] as $name => $owner) {
            [$key, $tokens[$name]] = ApiKey::generate($name);
            $key->setOwner($owner);
            $this->entityManager->persist($key);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->client->restart();
        $context = function (string $name) use ($tokens): array {
            $this->client->request('GET', '/api/context?cwd=/home/dev/shop&repository=github.com/acme/shop', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens[$name]]);

            return json_decode((string) $this->client->getResponse()->getContent(), true);
        };

        self::assertNull($context('carol')['project']);
        self::assertSame(['project' => 'shop', 'created' => true], array_intersect_key($context('bob'), ['project' => 1, 'created' => 1]));
        self::assertSame(['project' => 'shop', 'created' => false], array_intersect_key($context('bob'), ['project' => 1, 'created' => 1]));
        self::assertNull($context('carol')['project']);
        $this->entityManager->clear();
        self::assertSame(['shop'], $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => 'Back'])->getProjects()->map(static fn ($project): string => $project->getSlug())->getValues());
    }

    public function testEmailAndPasswordRegistrationAndSignIn(): void
    {
        $http = new MockHttpClient();
        self::getContainer()->set(OAuthClient::class, new OAuthClient($http, 'gh-id', 'gh-sec', 'gl-id', 'gl-sec', 'https://gitlab.com', 'gg-id', 'gg-sec'));

        // All three OAuth providers + email/password form are rendered on /login
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Continuer avec GitHub', 'Continuer avec GitLab', 'Continuer avec Google'],
            $crawler->filter('.login__provider')->each(static fn ($link): string => trim($link->text())),
        );
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
        self::assertSelectorExists('a[href="/register"]');

        // Register a listed admin email -> immediately active super admin
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/register', [
            '_csrf_token' => $token,
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'SuperSecret123!',
            'password_confirm' => 'SuperSecret123!',
        ]);
        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->logOut();

        // Register a non-admin email -> pending approval
        $crawler = $this->client->request('GET', '/register');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/register', [
            '_csrf_token' => $token,
            'name' => 'Clara User',
            'email' => 'clara@example.com',
            'password' => 'ClaraSecret123!',
            'password_confirm' => 'ClaraSecret123!',
        ]);
        self::assertResponseRedirects('/pending');
        $this->client->followRedirect();
        $this->logOut();

        // Sign back in with email & password via /login
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => 'alice@example.com',
            '_password' => 'SuperSecret123!',
        ]));
        self::assertResponseRedirects('/dashboard');
    }

    private function signIn(): void
    {
        $this->client->request('GET', '/oauth/github');
        parse_str((string) parse_url($this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        self::assertSame('client-id', $query['client_id']);
        self::assertSame('http://localhost/oauth/github/check', $query['redirect_uri']);
        $this->client->request('GET', '/oauth/github/check?code=abc&state='.$query['state']);
    }

    private function logOut(): void
    {
        $href = $this->client->getCrawler()->filter('a[href^="/logout"]')->attr('href');
        self::assertNotNull($href);
        $this->client->request('GET', $href);
    }

    private function user(string $email, string $name, UserStatus $status = UserStatus::Active, bool $superAdmin = false): User
    {
        $user = new User($email, $name, 'github', $email);
        $user->setStatus($status);
        $user->setSuperAdmin($superAdmin);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
