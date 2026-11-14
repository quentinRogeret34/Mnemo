<?php

namespace App\Tests\Chat;

use App\Chat\ProjectAssistant;
use App\Chat\ProjectTools;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\MemoryType;
use App\Enum\NodeType;
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

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

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
        self::assertStringContainsString('assistant du projet « Demo project »', $this->requests[0]);
        self::assertStringContainsString('Bonjour.', $this->requests[0]);
        self::assertStringContainsString('"role":"tool"', $this->requests[1]);
        self::assertStringContainsString('Une remise ne d', $this->requests[1]);
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
        self::assertCount(0, $component->call('reset')->render()->crawler()->filter('.chat__message'));

        $crawler = $this->client->request('GET', '/projects/demo/rules/RG-043');
        $this->client->submit($crawler->selectButton('Valider la règle')->form());
        $this->entityManager->clear();
        self::assertSame('validated', $this->entityManager->getRepository(Node::class)->findOneBy(['externalId' => 'RG-043'])->getMetadata()['status']);
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
        self::assertStringStartsWith('Mémoire #', $tools->actions()[0]['label']);
    }

    public function testThePageOffersTheChat(): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/chat');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.chat input#chat-message'));
        self::assertSame('Assistant', $crawler->filter('.sidebar a[aria-current="page"] .nav-item__label')->text());
    }

    /**
     * @param list<ToolCallResult|string> $answers one per call to the model, in order
     */
    private function scriptModel(array $answers): void
    {
        $platform = MockPlatformFactory::createPlatform(function ($model, array|string|object $input) use (&$answers): ToolCallResult|string {
            $this->requests[] = json_encode($input, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

            return array_shift($answers) ?? throw new \LogicException('The model was called more often than scripted.');
        });
        self::getContainer()->set(LlmSettings::class, new LlmSettings(new ServiceLocator(['openrouter' => static fn () => $platform]), 'openrouter', 'test-model', 'openrouter', 'test-embedding', 'test-key'));
    }
}
