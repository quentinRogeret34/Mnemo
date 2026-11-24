<?php

namespace App\Tests\Controller;

use App\Entity\AgentAsset;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StudioControllerTest extends WebTestCase
{
    public function testStudioCreatesAndEditsAgentAssetsAndMarkdownDocuments(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE agent_asset, node, edge, folder, project RESTART IDENTITY CASCADE');

        $project = new Project('mnemo', 'Mnemo', '/tmp/mnemo');
        $em->persist($project);
        $em->flush();

        // 1. Open Studio — shows declarative project.yaml by default + collapsible sidebar dropdowns
        $crawler = $client->request('GET', '/projects/mnemo/studio');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-topbar__file', 'project.yaml');
        self::assertStringContainsString('schemaVersion: mnemo/v1', $crawler->filter('.studio-textarea')->text());
        self::assertGreaterThanOrEqual(2, $crawler->filter('.sidebar details.nav-accordion')->count());

        // 2. Create a skill directly from the Studio modal
        $createToken = (string) $crawler->filter('#studio-new-file input[name="_token"]')->attr('value');
        $client->request('POST', '/projects/mnemo/studio/create', [
            '_token' => $createToken,
            'kind' => 'skill',
            'name' => 'Revue PR',
            'description' => 'Vérifie la conformité SOLID et les règles métier.',
        ]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-topbar__file', 'revue-pr');

        $asset = $em->getRepository(AgentAsset::class)->findOneBy(['project' => $project, 'name' => 'revue-pr']);
        self::assertNotNull($asset);

        // 3. Edit and save the skill inside the Studio editor
        $saveToken = (string) $crawler->filter('#studio-editor-form input[name="_token"]')->attr('value');
        $client->request('POST', '/projects/mnemo/studio/save', [
            '_token' => $saveToken,
            'ref' => 'asset:'.$asset->getId(),
            'description' => 'Skill mis à jour depuis Studio.',
            'content' => "# Revue PR\n\nToujours vérifier les règles RG-*.",
        ]);
        self::assertResponseRedirects('/projects/mnemo/studio?file=asset:'.$asset->getId());
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $em->clear();
        $reloadedAsset = $em->find(AgentAsset::class, $asset->getId());
        self::assertNotNull($reloadedAsset);
        self::assertSame('Skill mis à jour depuis Studio.', $reloadedAsset->getDescription());
        self::assertStringContainsString('Toujours vérifier les règles RG-*.', $reloadedAsset->getContent());

        // 4. Create and edit a Markdown document from Studio
        $client->request('POST', '/projects/mnemo/studio/create', [
            '_token' => $createToken,
            'kind' => 'document',
            'name' => 'Architecture Studio',
            'description' => 'Vue initiale.',
            'folder' => 'Architecture',
        ]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $doc = $em->getRepository(Node::class)->findOneBy(['type' => NodeType::Document, 'label' => 'Architecture Studio']);
        self::assertNotNull($doc);

        $docSaveToken = (string) $crawler->filter('#studio-editor-form input[name="_token"]')->attr('value');
        $client->request('POST', '/projects/mnemo/studio/save', [
            '_token' => $docSaveToken,
            'ref' => 'doc:'.$doc->getId(),
            'content' => "# Architecture Studio v2\n\nContenu édité directement dans le Studio.",
        ]);
        self::assertResponseRedirects('/projects/mnemo/studio?file=doc:'.$doc->getId());
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Contenu édité directement dans le Studio.', $crawler->filter('.studio-textarea')->text());
    }
}
