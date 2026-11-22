<?php

namespace App\Tests\Security;

use App\Entity\ApiKey;
use App\Entity\Connector;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\ConnectorMode;
use App\Enum\UserStatus;
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

    public function testAUserLimitedToSomeProjectsReachesNoConnector(): void
    {
        $connector = new Connector();
        $connector->setName('Forge');
        $connector->setMode(ConnectorMode::GitHub);
        $connector->setEndpoint('https://api.github.com');
        $connector->setArguments(['repository' => 'acme/shop']);
        $connector->setProject($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'other']));
        $this->entityManager->persist($connector);
        $this->client->loginUser($this->member('bob', 'demo'));
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
