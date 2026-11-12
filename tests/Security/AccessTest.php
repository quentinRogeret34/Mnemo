<?php

namespace App\Tests\Security;

use App\Entity\ApiKey;
use App\Entity\Project;
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
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, api_key RESTART IDENTITY CASCADE');
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

        $this->client->request('GET', '/api/context?cwd=/repos/demo', server: ['HTTP_AUTHORIZATION' => 'Bearer gk_wrong']);
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/settings/keys');
        $this->client->submit($crawler->selectButton('Révoquer')->form());
        $this->client->restart();
        $this->client->request('GET', '/api/context?cwd=/repos/demo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(401);
    }
}
