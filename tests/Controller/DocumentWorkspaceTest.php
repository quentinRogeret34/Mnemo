<?php

namespace App\Tests\Controller;

use App\Document\DocumentStorage;
use App\Entity\Edge;
use App\Entity\Folder;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeStatus;
use App\Enum\NodeType;
use App\Ingest\GraphifyImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class DocumentWorkspaceTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Project $project;
    private Project $otherProject;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        $this->project = new Project('demo', 'Demo project', '/repos/demo');
        $this->otherProject = new Project('other', 'Other project', '/repos/other');
        $this->entityManager->persist($this->project);
        $this->entityManager->persist($this->otherProject);
        $this->entityManager->flush();
        self::getContainer()->get(GraphifyImporter::class)->import($this->project, __DIR__.'/../Fixtures/graphify/graph-v1.json');
    }

    public function testFoldersNestAndReceiveUploads(): void
    {
        $crawler = $this->client->request('GET', '/documents');
        $this->client->submit($crawler->selectButton('Créer le dossier')->form(['folder[name]' => 'Spécifications']));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Créer le dossier')->form(['folder[name]' => 'Facturation']));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Documents', 'Spécifications', 'Facturation'], $crawler->filter('.crumbs > a, .crumbs > .crumbs__current')->each(static fn ($crumb): string => $crumb->text()));

        $markdown = tempnam(sys_get_temp_dir(), 'doc').'.md';
        file_put_contents($markdown, "# Remises\n\nTexte.\n");
        $form = $crawler->selectButton('Ajouter')->form();
        $form['document_upload[files]'][0]->upload($markdown);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();

        self::assertSame('Remises', $crawler->filter('.explorer__content .row__title > span')->last()->text());
        self::assertSame(['Tous les dossiers', 'Spécifications', 'Facturation 1'], $crawler->filter('.tree__item')->each(static fn ($item): string => trim(preg_replace('/\s+/', ' ', $item->text()))));
        self::assertSame('--depth: 2', $crawler->filter('.tree__item')->last()->attr('style'));

        $document = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::Document]);
        self::assertSame('Facturation', $document->getFolder()->getName());
        self::assertSame('Spécifications', $document->getFolder()->getParent()->getName());
        self::assertCount(0, $document->getProjects());
    }

    public function testUploadFromProjectAttachesTheDocument(): void
    {
        $markdown = tempnam(sys_get_temp_dir(), 'doc').'.md';
        file_put_contents($markdown, "# Glossaire\n");

        $crawler = $this->client->request('GET', '/projects/demo');
        $form = $crawler->selectButton('Ajouter')->form();
        $form['document_upload[files]'][0]->upload($markdown);
        $this->client->submit($form);

        self::assertResponseRedirects('/projects/demo');
        $this->client->request('GET', '/projects/other');
        self::assertSelectorNotExists('.content .row');
        $crawler = $this->client->request('GET', '/projects/demo');
        self::assertSame('Glossaire', $crawler->filter('.content .row__title > span')->first()->text());
    }

    public function testPropertiesAttachProjectsMoveAndLinkCode(): void
    {
        $document = $this->givenDocument();
        $folder = new Folder('Contrats');
        $this->entityManager->persist($folder);
        $this->entityManager->flush();

        $component = $this->createLiveComponent('DocumentProperties', ['document' => $document]);
        self::assertStringContainsString('Rattacher d\'abord le document à un projet', (string) $component->render());

        $component->call('toggleProject', ['projectId' => $this->project->getId()]);
        $component->call('toggleProject', ['projectId' => $this->otherProject->getId()]);
        $component->set('folderId', $folder->getId());

        $component->set('fileQuery', 'catalog');
        $results = $component->render()->crawler()->filter('.link-search__item');
        self::assertCount(1, $results);
        self::assertStringContainsString('src/domain/catalog.ts', $results->text());

        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/domain/catalog.ts']);
        $component->call('linkFile', ['fileId' => $file->getId()]);

        $this->entityManager->clear();
        $document = $this->entityManager->find(Node::class, $document->getId());
        self::assertSame(['Demo project', 'Other project'], $document->getProjects()->map(static fn (Project $project): string => $project->getName())->getValues());
        self::assertSame('Contrats', $document->getFolder()->getName());

        $edge = $this->entityManager->getRepository(Edge::class)->findOneBy(['target' => $document, 'relation' => 'documented_by']);
        self::assertSame(Confidence::Verified, $edge->getConfidence());
        self::assertSame('src/domain/catalog.ts', $edge->getSource()->getPath());

        $crawler = $this->client->request('GET', '/projects/demo/files/src/domain/catalog.ts');
        self::assertSame('Contrat cadre', $crawler->filter('section[aria-labelledby="docs-title"] .row__title')->text());
        $crawler = $this->client->request('GET', '/projects/other');
        self::assertSame('Contrat cadre', $crawler->filter('.content .row__title > span')->first()->text());

        $component->call('unlinkFile', ['edgeId' => $edge->getId()]);
        $this->entityManager->clear();
        self::assertSame(EdgeStatus::Rejected, $this->entityManager->find(Edge::class, $edge->getId())->getStatus());
        $this->client->request('GET', '/projects/demo/files/src/domain/catalog.ts');
        self::assertSelectorTextContains('section[aria-labelledby="docs-title"]', 'Aucun document relié');

        $component->call('linkFile', ['fileId' => $file->getId()]);
        $this->entityManager->clear();
        self::assertSame(EdgeStatus::Active, $this->entityManager->find(Edge::class, $edge->getId())->getStatus());
    }

    public function testCodeOfAProjectTheDocumentIsNotAttachedToCannotBeLinked(): void
    {
        $document = $this->givenDocument();
        $file = $this->entityManager->getRepository(Node::class)->findOneBy(['type' => NodeType::CodeFile, 'path' => 'src/main.ts']);

        $this->expectException(\InvalidArgumentException::class);
        $this->createLiveComponent('DocumentProperties', ['document' => $document])->call('linkFile', ['fileId' => $file->getId()]);
    }

    private function givenDocument(): Node
    {
        $markdown = tempnam(sys_get_temp_dir(), 'doc').'.md';
        file_put_contents($markdown, "# Contrat cadre\n");
        $document = self::getContainer()->get(DocumentStorage::class)->store(null, new \SplFileInfo($markdown), 'contrat.md');
        $this->entityManager->flush();

        return $document;
    }
}
