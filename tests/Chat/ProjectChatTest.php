<?php

namespace App\Tests\Chat;

use App\Chat\ProjectAssistant;
use App\Chat\ProjectTools;
use App\Entity\ChatConversation;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\UserStatus;
use App\Llm\LlmSettings;
use App\Memory\MemoryStore;
use App\Twig\Components\ProjectChat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ProjectChatTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;
    /** @var list<string> the conversation as the model received it, one JSON per call */
    private array $requests = [];
    /** @var list<string> the tools as the model received them, one JSON per call */
    private array $tools = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, chat_conversation, app_user RESTART IDENTITY CASCADE');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->entityManager->persist($this->project);
        $this->entityManager->persist(new Node($this->project, NodeType::BusinessRule, 'RG-042', 'Remise plafonnée', null, null, ['statement' => 'Une remise ne dépasse jamais 10 %.', 'status' => 'validated']));
        $memory = self::getContainer()->get(MemoryStore::class)->add('Les remises passent par catalog.ts.', MemoryType::Convention, 'manual');
        $memory->addProject($this->project);
        $this->entityManager->flush();
    }

    public function testTheAssistantReadsTheGraphBeforeAnswering(): void
    {
        $this->scriptModel([new ToolCallResult([new ToolCall('call-1', 'get_rule', ['code' => 'RG-042'])]), 'La remise est plafonnée à 10 % (RG-042).']);

        $reply = self::getContainer()->get(ProjectAssistant::class)->reply($this->project, [['role' => 'user', 'content' => 'Bonjour'], ['role' => 'assistant', 'content' => 'Bonjour.']], 'Quel est le plafond des remises ?');

        self::assertSame(['answer' => 'La remise est plafonnée à 10 % (RG-042).', 'actions' => []], $reply);
        self::assertCount(2, $this->requests);
        self::assertStringContainsString('assistant of the \\"Demo project\\" project', $this->requests[0]);
        self::assertStringContainsString('Bonjour.', $this->requests[0]);
        self::assertStringContainsString('"role":"tool"', $this->requests[1]);
        self::assertStringContainsString('Une remise ne d', $this->requests[1]);
        // Mistral refuses a tool without "parameters": list_rules takes no argument.
        self::assertStringContainsString('"name":"list_rules","description":"Lists the business rules of the project: code, title, statement and status (validated or to_review).","parameters":{"type":"object","properties":{}}', $this->tools[0]);
        self::assertSame(substr_count($this->tools[0], '"name":'), substr_count($this->tools[0], '"parameters":'));
    }

    public function testARuleAddedInTheChatWaitsForValidationAndIsShownInTheConversation(): void
    {
        $this->scriptModel([
            new ToolCallResult([new ToolCall('call-1', 'add_rule', ['title' => 'Validation des grosses commandes', 'statement' => 'Une commande de plus de 500 € demande une validation.'])]),
            'Règle RG-043 enregistrée, à valider.',
        ]);

        $component = $this->createLiveComponent(ProjectChat::class, ['project' => $this->project], $this->client);
        $crawler = $component->set('message', 'Ajoute une règle : une commande de plus de 500 € demande une validation.')->call('send')->render()->crawler();

        self::assertSame('Ajoute une règle : une commande de plus de 500 € demande une validation.', $crawler->filter('.chat__message--user p')->text());
        self::assertSame('Règle RG-043 enregistrée, à valider.', $crawler->filter('.chat__message--assistant .prose')->text());
        self::assertSame('/projects/demo/rules/RG-043', $crawler->filter('.chat__message--assistant a.tag')->attr('href'));
        $rule = $this->entityManager->getRepository(Node::class)->findOneBy(['project' => $this->project, 'externalId' => 'RG-043']);
        self::assertSame(['statement' => 'Une commande de plus de 500 € demande une validation.', 'status' => 'to_review', 'source' => 'chat'], $rule->getMetadata());

        // Starting a new conversation clears the active view while keeping the conversation in history.
        $afterReset = $component->call('reset')->render()->crawler();
        self::assertCount(0, $afterReset->filter('.chat__message'));
        self::assertCount(1, $afterReset->filter('.chat-history__item'));

        $crawler = $this->client->request('GET', '/projects/demo/rules/RG-043');
        $this->client->submit($crawler->selectButton('Valider la règle')->form());
        $this->entityManager->clear();
        self::assertSame('validated', $this->entityManager->getRepository(Node::class)->findOneBy(['externalId' => 'RG-043'])->getMetadata()['status']);
    }

    public function testHistoryPersistenceSearchRegenerateFeedbackAndDeletion(): void
    {
        $this->scriptModel([
            'Réponse initiale sur les remises.',
            'Réponse régénérée sur les remises.',
            'Réponse sur OAuth2 et sessions.',
        ]);

        $component = $this->createLiveComponent(ProjectChat::class, ['project' => $this->project], $this->client);
        $crawler = $component->set('message', 'Explique les remises')->call('send')->render()->crawler();

        self::assertCount(1, $crawler->filter('.chat-history__item'));
        self::assertSame('1/50', trim($crawler->filter('.chat-history__count')->text()));
        self::assertSame('Réponse initiale sur les remises.', $crawler->filter('.chat__message--assistant .prose')->text());

        // Regenerate replaces the last assistant answer with a new call using the same prompt.
        $crawler = $component->call('regenerate')->render()->crawler();
        self::assertCount(2, $crawler->filter('.chat__message'));
        self::assertSame('Réponse régénérée sur les remises.', $crawler->filter('.chat__message--assistant .prose')->text());

        // Thumbs-up feedback persists and toggles off when clicked a second time.
        $assistantId = $crawler->filter('.chat__message--assistant')->attr('data-message-id');
        self::assertNotNull($assistantId);
        $crawler = $component->call('rate', ['messageId' => $assistantId, 'value' => 'up'])->render()->crawler();
        self::assertSame('true', $crawler->filter('.chat__tool[data-live-value-param="up"]')->attr('aria-pressed'));
        $crawler = $component->call('rate', ['messageId' => $assistantId, 'value' => 'up'])->render()->crawler();
        self::assertSame('false', $crawler->filter('.chat__tool[data-live-value-param="up"]')->attr('aria-pressed'));

        // Start a second conversation.
        $component->call('newConversation');
        $crawler = $component->set('message', 'Comment fonctionne OAuth2 ?')->call('send')->render()->crawler();
        self::assertCount(2, $crawler->filter('.chat-history__item'));
        self::assertSame('2/50', trim($crawler->filter('.chat-history__count')->text()));

        // Search filters the history list.
        $filtered = $component->set('search', 'remises')->render()->crawler();
        self::assertCount(1, $filtered->filter('.chat-history__item'));
        self::assertStringContainsString('Explique les remises', $filtered->filter('.chat-history__title')->text());

        // Switching back to the first conversation reloads its persisted messages.
        $conversations = $this->entityManager->getRepository(ChatConversation::class)->findBy(['project' => $this->project], ['id' => 'ASC']);
        self::assertCount(2, $conversations);
        $firstId = $conversations[0]->getId();
        $secondId = $conversations[1]->getId();
        self::assertNotNull($firstId);
        self::assertNotNull($secondId);

        $switched = $component->set('search', '')->call('selectConversation', ['id' => $firstId])->render()->crawler();
        self::assertSame('Réponse régénérée sur les remises.', $switched->filter('.chat__message--assistant .prose')->text());

        // Batch delete removes selected conversations only.
        $afterBatch = $component->set('selectedIds', [(string) $firstId])->call('deleteSelected')->render()->crawler();
        self::assertCount(1, $afterBatch->filter('.chat-history__item'));
        self::assertCount(0, $afterBatch->filter('.chat__message'));

        // Clear history removes all remaining conversations for this project.
        $afterClear = $component->call('clearHistory')->render()->crawler();
        self::assertCount(0, $afterClear->filter('.chat-history__item'));
        self::assertSame('0/50', trim($afterClear->filter('.chat-history__count')->text()));
    }

    public function testConversationsStayPrivatePerOwner(): void
    {
        $alice = new User('alice@example.test', 'Alice', 'github', 'alice-1');
        $alice->setStatus(UserStatus::Active);
        $alice->setSuperAdmin(true);
        $bob = new User('bob@example.test', 'Bob', 'github', 'bob-1');
        $bob->setStatus(UserStatus::Active);
        $bob->setSuperAdmin(true);
        $this->entityManager->persist($alice);
        $this->entityManager->persist($bob);
        $this->entityManager->flush();

        $aliceConv = new ChatConversation($this->project, 'user:'.$alice->getId(), 'Secret d\'Alice');
        $aliceConv->appendExchange('Secret d\'Alice', 'Réponse privée.', []);
        $this->entityManager->persist($aliceConv);
        $this->entityManager->flush();

        $this->client->loginUser($bob);
        $component = $this->createLiveComponent(ProjectChat::class, ['project' => $this->project], $this->client);
        $crawler = $component->call('selectConversation', ['id' => (int) $aliceConv->getId()])->render()->crawler();

        self::assertCount(0, $crawler->filter('.chat-history__item'));
        self::assertCount(0, $crawler->filter('.chat__message'));

        // Bob cannot delete Alice's conversation.
        $component->call('deleteConversation', ['id' => (int) $aliceConv->getId()]);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(ChatConversation::class, $aliceConv->getId()));
    }

    public function testTheToolsStayInsideTheProjectAndRecordWhatTheyWrite(): void
    {
        $other = new Project('other', 'Other', '/repos/other');
        $this->entityManager->persist($other);
        $this->entityManager->persist(new Node($other, NodeType::BusinessRule, 'RG-001', 'Règle d\'un autre projet', null, null, ['statement' => 'Ailleurs.', 'status' => 'validated']));
        $this->entityManager->flush();
        $tools = self::getContainer()->get(ProjectTools::class)->for($this->project);

        self::assertSame(['RG-042'], array_column($tools->listRules(), 'code'));
        self::assertArrayHasKey('error', $tools->getRule('RG-001'));
        self::assertContains('Les remises passent par catalog.ts.', array_column($tools->search('remises'), 'label'));

        self::assertSame('ADD', $tools->addMemory('Le plafond des remises se règle dans catalog.ts.', 'decision')['event']);
        self::assertSame('NONE', $tools->addMemory('Le plafond des remises se règle dans catalog.ts.', 'decision')['event']);
        self::assertArrayHasKey('error', $tools->addMemory('Texte', 'inconnu'));
        self::assertCount(1, $tools->actions());
        self::assertSame('chat.action.memory', $tools->actions()[0]['label']);
        self::assertArrayHasKey('id', $tools->actions()[0]['parameters']);
    }

    public function testThePageOffersTheChat(): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/chat');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.chat textarea#chat-message'));
        self::assertCount(1, $crawler->filter('.chat-history'));
        self::assertSame('Assistant', $crawler->filter('.sidebar a[aria-current="page"] .nav-item__label')->text());
    }

    /**
     * @param list<ToolCallResult|string> $answers one per call to the model, in order
     */
    private function scriptModel(array $answers): void
    {
        $platform = MockPlatformFactory::createPlatform(function ($model, array|string|object $input, array $options = []) use (&$answers): ToolCallResult|string {
            $this->requests[] = json_encode($input, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            $this->tools[] = json_encode($options['tools'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

            return array_shift($answers) ?? throw new \LogicException('The model was called more often than scripted.');
        });
        self::getContainer()->set(LlmSettings::class, new LlmSettings(new ServiceLocator(['openrouter' => static fn () => $platform]), 'openrouter', 'test-model', 'openrouter', 'test-embedding', 'test-key'));
    }
}
