<?php

namespace App\Tests\Controller;

use App\Entity\AgentAsset;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AgentAssetTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, agent_asset RESTART IDENTITY CASCADE');
        $this->entityManager->persist(new Project('demo', 'Demo project', '/repos/demo'));
        $this->entityManager->persist(new Project('other', 'Other', '/repos/other'));
        $this->entityManager->flush();
    }

    public function testASkillWrittenOnTheProjectIsServedToItsAgentsUntilItIsDeleted(): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/agent-assets');
        self::assertStringContainsString('Aucun skill', $crawler->filter('main')->text());

        $crawler = $this->client->request('GET', '/projects/demo/agent-assets/new');
        $form = $crawler->selectButton('Enregistrer')->form(['agent_asset[name]' => 'Revue De PR', 'agent_asset[description]' => 'Relire une PR.', 'agent_asset[content]' => 'Lire le diff.']);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Minuscules, chiffres et tirets', $this->client->getResponse()->getContent());

        $this->save('skill', 'revue-de-pr', 'Relire une PR : "règles" touchées.', "Lire le diff.\n\nCiter les règles.");
        $this->save('command', 'bilan', 'Bilan de la session.', "---\ndescription: Bilan\nallowed-tools: Read\n---\n\nRésumer.");
        $this->save('agent', 'relecteur', 'Relit le code.', 'Tu relis.');
        $crawler = $this->client->request('GET', '/projects/demo/agent-assets');
        self::assertSame(['.claude/agents/relecteur.md', '.claude/commands/bilan.md', '.claude/skills/revue-de-pr/SKILL.md'], $crawler->filter('tbody td.mono')->each(static fn ($cell): string => $cell->text()));

        self::assertSame([
            ['path' => '.claude/skills/revue-de-pr/SKILL.md', 'content' => "---\nname: revue-de-pr\ndescription: \"Relire une PR : \\\"règles\\\" touchées.\"\n---\n\nLire le diff.\n\nCiter les règles.\n"],
            ['path' => '.claude/commands/bilan.md', 'content' => "---\ndescription: Bilan\nallowed-tools: Read\n---\n\nRésumer.\n"],
            ['path' => '.claude/agents/relecteur.md', 'content' => "---\nname: relecteur\ndescription: \"Relit le code.\"\n---\n\nTu relis.\n"],
        ], $this->served('demo'));
        self::assertSame([], $this->served('other'));

        $skill = $this->entityManager->getRepository(AgentAsset::class)->findOneBy(['name' => 'revue-de-pr']);
        $this->client->request('GET', '/projects/other/agent-assets/'.$skill->getId());
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/projects/demo/agent-assets');
        $this->client->submit($crawler->selectButton('Supprimer')->eq(0)->form());
        self::assertResponseRedirects('/projects/demo/agent-assets');
        self::assertCount(2, $this->served('demo'));
    }

    public function testTwoFilesOfTheSameKindCannotShareAName(): void
    {
        $this->save('skill', 'revue', 'Relire.', 'Lire.');
        $this->save('command', 'revue', 'Relire.', 'Lire.');
        $this->save('skill', 'revue', 'Relire encore.', 'Lire.');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ce nom est déjà pris', $this->client->getResponse()->getContent());
        self::assertCount(2, $this->served('demo'));
    }

    private function save(string $kind, string $name, string $description, string $content): void
    {
        $crawler = $this->client->request('GET', '/projects/demo/agent-assets/new');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'agent_asset[kind]' => $kind, 'agent_asset[name]' => $name, 'agent_asset[description]' => $description, 'agent_asset[content]' => $content,
        ]));
    }

    /**
     * @return list<array{path: string, content: string}>
     */
    private function served(string $project): array
    {
        $this->client->request('GET', '/api/agent-assets?project='.$project);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true)['files'];
    }
}
