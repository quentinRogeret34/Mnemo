<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Setting;
use App\Entity\User;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\UserStatus;
use App\Llm\LlmSettings;
use App\Memory\MemoryStore;
use App\Message\ReindexNodes;
use App\MessageHandler\ReindexNodesHandler;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class LlmSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    /** @var list<array{key: string, model: string, kind: string}> calls received by OpenRouter */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, setting RESTART IDENTITY CASCADE');

        // OpenRouter itself is the only thing replaced: the key and the models go through the real platform.
        $http = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $model = json_decode($options['body'], true)['model'];
            $kind = str_ends_with($url, '/embeddings') ? 'embeddings' : 'chat';
            $this->calls[] = ['key' => substr(implode('', preg_grep('/^Authorization:/i', $options['headers'])), \strlen('Authorization: Bearer ')), 'model' => $model, 'kind' => $kind];
            if (str_contains($model, 'unknown')) {
                return new JsonMockResponse(['error' => ['message' => 'No such model', 'code' => 400]], ['http_code' => 400]);
            }

            return new JsonMockResponse('embeddings' === $kind
                ? ['data' => [['embedding' => [0.1, 0.2, 0.3], 'index' => 0]]]
                : ['choices' => [['message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']]]);
        });
        self::getContainer()->set(LlmSettings::class, new LlmSettings(
            new ServiceLocator(['openrouter' => static fn (): never => throw new \LogicException('No key in the environment here.')]),
            'openrouter', 'env/chat', 'openrouter', 'mistralai/mistral-embed-2312', '',
            $this->entityManager, self::getContainer()->get(SecretBox::class), $http,
        ));
    }

    public function testTheKeyAndTheModelsOfTheInstanceAreSetFromTheInterface(): void
    {
        $crawler = $this->client->request('GET', '/settings/llm');
        self::assertStringContainsString('absente', $crawler->filter('.props')->text());

        $this->client->submit($crawler->selectButton('Enregistrer')->form(['llm_settings[apiKey]' => 'sk-or-secret', 'llm_settings[chatModel]' => 'acme/unknown-chat']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('OpenRouter a refusé le modèle de chat avec cette clé', $this->client->getCrawler()->filter('form')->text());
        self::assertNull($this->entityManager->find(Setting::class, LlmSettings::KEY));

        $crawler = $this->client->request('GET', '/settings/llm');
        $this->calls = [];
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['llm_settings[apiKey]' => 'sk-or-secret', 'llm_settings[chatModel]' => 'acme/chat', 'llm_settings[embeddingModel]' => 'openai/text-embedding-3-small']));
        self::assertResponseRedirects('/settings/llm');
        self::assertSame([['key' => 'sk-or-secret', 'model' => 'acme/chat', 'kind' => 'chat'], ['key' => 'sk-or-secret', 'model' => 'openai/text-embedding-3-small', 'kind' => 'embeddings']], $this->calls);
        self::assertEquals([new ReindexNodes()], $this->queued(ReindexNodes::class));

        $stored = $this->entityManager->find(Setting::class, LlmSettings::KEY)->getValue();
        self::assertStringNotContainsString('sk-or-secret', $stored);
        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString('sk-or-secret', $this->client->getResponse()->getContent());
        self::assertStringContainsString('enregistrée ici', $crawler->filter('.props')->text());
        $settings = self::getContainer()->get(LlmSettings::class);
        self::assertSame(['acme/chat', 'openai/text-embedding-3-small', true], [$settings->chatModel(), $settings->embeddingModel(), $settings->isConfigured('openrouter')]);

        // An empty key keeps the stored one; empty models fall back to those of the server.
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['llm_settings[chatModel]' => '', 'llm_settings[embeddingModel]' => 'openai/text-embedding-3-small']));
        self::assertResponseRedirects('/settings/llm');
        self::assertSame(['env/chat', 'openai/text-embedding-3-small', true], [$settings->chatModel(), $settings->embeddingModel(), $settings->isConfigured('openrouter')]);
        // The transport is emptied between requests: the same embedding model asks for no new indexing.
        self::assertCount(0, $this->queued(ReindexNodes::class));

        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['llm_settings[removeKey]']->tick();
        $this->client->submit($form);
        self::assertFalse($settings->isConfigured('openrouter'));
    }

    public function testAProjectNamesItsOwnModelsAndItsContentIsIndexedWithThem(): void
    {
        $this->entityManager->persist(new Setting(LlmSettings::KEY, self::getContainer()->get(SecretBox::class)->encrypt('sk-or-secret')));
        $project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($project);
        $this->entityManager->persist(new Node($project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'validated']));
        $shared = self::getContainer()->get(MemoryStore::class)->add('Les remises passent par catalog.ts.', MemoryType::Convention, 'manual');
        $shared->addProject($project);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/projects/demo/settings');
        $this->client->submit($crawler->selectButton('Enregistrer les réglages')->form(['project[embeddingModel]' => 'acme/unknown-embedding']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('modèle d\'embeddings inconnu', $this->client->getCrawler()->filter('form.project-settings')->text());

        $crawler = $this->client->request('GET', '/projects/demo/settings');
        $this->client->submit($crawler->selectButton('Enregistrer les réglages')->form(['project[chatModel]' => 'acme/project-chat', 'project[embeddingModel]' => 'baai/bge-m3']));
        self::assertResponseRedirects('/projects/demo/settings');
        $settings = self::getContainer()->get(LlmSettings::class);
        $this->entityManager->clear();
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo']);
        self::assertSame(['acme/project-chat', 'baai/bge-m3', 'env/chat'], [$settings->chatModel($project), $settings->embeddingModel($project), $settings->chatModel()]);
        self::assertEquals([new ReindexNodes($project->getId())], $this->queued(ReindexNodes::class));

        self::getContainer()->get(ReindexNodesHandler::class)(new ReindexNodes($project->getId()));
        $rows = $this->entityManager->getConnection()->fetchFirstColumn("SELECT n.type || ' ' || e.model FROM node_embedding e JOIN node n ON n.id = e.node_id ORDER BY 1");
        self::assertSame([
            'business_rule openrouter/baai/bge-m3', 'business_rule openrouter/mistralai/mistral-embed-2312',
            'memory openrouter/baai/bge-m3', 'memory openrouter/mistralai/mistral-embed-2312',
        ], $rows);
    }

    public function testOnlyASuperAdminChangesTheKeyOnceTheServerIsShared(): void
    {
        $this->entityManager->getConnection()->executeStatement('TRUNCATE app_user RESTART IDENTITY CASCADE');
        $bob = new User('bob@example.com', 'Bob', 'github', 'bob');
        $bob->setStatus(UserStatus::Active);
        $this->entityManager->persist($bob);
        $this->entityManager->flush();

        $this->client->loginUser($bob);
        $crawler = $this->client->request('GET', '/settings/keys');
        self::assertStringNotContainsString('Modèles de langage', $crawler->filter('.sidebar')->text());
        $this->client->request('GET', '/settings/llm');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/settings/llm');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Modèles de langage', $crawler->filter('.sidebar')->text());
    }

    /**
     * @return list<object>
     */
    private function queued(string $class): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), self::getContainer()->get('messenger.transport.async')->getSent()),
            static fn (object $message): bool => $message instanceof $class,
        ));
    }
}
