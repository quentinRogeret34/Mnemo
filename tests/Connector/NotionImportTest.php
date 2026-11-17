<?php

namespace App\Tests\Connector;

use App\Connector\ConnectorRunner;
use App\Connector\CredentialResolver;
use App\Document\DocumentStorage;
use App\Entity\Connector;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorTransport;
use App\Enum\NodeType;
use App\Notion\NotionClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class NotionImportTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector RESTART IDENTITY CASCADE');
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');

        self::getContainer()->set(NotionClient::class, new NotionClient(new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $this->requests[] = $method.' '.$url;
            self::assertContains('Notion-Version: 2022-06-28', $options['headers']);
            self::assertContains('Authorization: Bearer secret_test', $options['headers']);

            return new JsonMockResponse(match (strtok(substr($url, \strlen('https://api.notion.com/v1/')), '?')) {
                'users/me' => ['name' => 'Mnemo', 'bot' => ['workspace_name' => 'Acme']],
                'search' => ['results' => [
                    self::page('spec', 'Spécification paiement', '2026-11-17T10:00:00.000Z'),
                    ['archived' => true] + self::page('old', 'Brouillon jeté', '2026-11-17T09:00:00.000Z'),
                    self::page('notes', 'Notes de réunion', '2026-11-16T09:00:00.000Z'),
                ], 'has_more' => false, 'next_cursor' => null],
                'blocks/spec/children' => self::blocks([
                    self::block('h', 'heading_1', ['rich_text' => self::text('Règles')]),
                    self::block('p', 'paragraph', ['rich_text' => [
                        ['plain_text' => 'Un remboursement passe par ', 'annotations' => []],
                        ['plain_text' => 'Stripe', 'annotations' => ['bold' => true], 'href' => 'https://stripe.com'],
                    ]]),
                    self::block('li1', 'bulleted_list_item', ['rich_text' => self::text('Carte')], true),
                    self::block('li2', 'bulleted_list_item', ['rich_text' => self::text('Virement')]),
                    self::block('n1', 'numbered_list_item', ['rich_text' => self::text('Valider')]),
                    self::block('n2', 'numbered_list_item', ['rich_text' => self::text('Rembourser')]),
                    self::block('todo', 'to_do', ['rich_text' => self::text('Relire'), 'checked' => true]),
                    self::block('code', 'code', ['rich_text' => self::text('refund(42)'), 'language' => 'php']),
                    self::block('table', 'table', ['table_width' => 2], true),
                    self::block('img', 'image', ['type' => 'file', 'file' => ['url' => 'https://s3.example/expires'], 'caption' => self::text('Schéma du flux')]),
                    self::block('sub', 'child_page', ['title' => 'Annexes'], true),
                ]),
                'blocks/li1/children' => self::blocks([self::block('li1a', 'bulleted_list_item', ['rich_text' => self::text('Visa')])]),
                'blocks/table/children' => self::blocks([
                    self::block('r1', 'table_row', ['cells' => [self::text('Moyen'), self::text('Délai')]]),
                    self::block('r2', 'table_row', ['cells' => [self::text('Carte'), self::text('5 jours')]]),
                ]),
                'blocks/notes/children' => self::blocks([self::block('n', 'paragraph', ['rich_text' => self::text('Décision : rembourser sous 5 jours.')])]),
                default => self::fail('Unexpected Notion request: '.$url),
            });
        }), self::getContainer()->get(CredentialResolver::class)));
    }

    public function testEachSharedPageBecomesAMarkdownDocumentAndOnlyEditedPagesAreReadAgain(): void
    {
        $project = new Project('demo', 'Demo project', '/repos/demo');
        $connector = new Connector();
        $connector->setName('Notion');
        $connector->setTransport(ConnectorTransport::Http);
        $connector->setEndpoint('https://api.notion.com/v1');
        $connector->setCredentials(['Authorization' => 'Bearer secret_test']);
        $connector->setMode(ConnectorMode::Notion);
        $connector->setProject($project);
        $this->entityManager->persist($project);
        $this->entityManager->persist($connector);
        $this->entityManager->flush();
        $runner = self::getContainer()->get(ConnectorRunner::class);

        $inspection = $runner->inspect($connector);
        self::assertSame('Notion Acme · Mnemo', $inspection['server']);
        self::assertSame(['Spécification paiement', 'Notes de réunion'], array_column($inspection['resources'], 'name'));

        self::assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'error' => null], $runner->sync($connector));
        $documents = $this->entityManager->getRepository(Node::class)->findBy(['type' => NodeType::Document], ['label' => 'ASC']);
        self::assertSame(['Notes de réunion', 'Spécification paiement'], array_map(static fn (Node $document): string => $document->getLabel(), $documents));
        self::assertSame(['demo'], $documents[1]->getProjects()->map(static fn (Project $project): string => $project->getSlug())->getValues());
        self::assertSame(<<<'MD'
            ## Règles

            Un remboursement passe par [**Stripe**](https://stripe.com)

            - Carte
              - Visa
            - Virement
            1. Valider
            2. Rembourser
            - [x] Relire

            ```php
            refund(42)
            ```

            | Moyen | Délai |
            | --- | --- |
            | Carte | 5 jours |

            _Schéma du flux_

            **Annexes**
            MD, trim(self::getContainer()->get(DocumentStorage::class)->markdownBody($documents[1])));
        self::assertNotContains('GET https://api.notion.com/v1/blocks/sub/children?page_size=100', $this->requests);
        self::assertSame(['pages' => '2026-11-17T10:00:00.000Z'], $connector->getCursor());

        $this->requests = [];
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'skipped' => [], 'error' => null], $runner->sync($connector));
        self::assertNotContains('GET https://api.notion.com/v1/blocks/notes/children?page_size=100', $this->requests);
    }

    /**
     * @return array<string, mixed>
     */
    private static function page(string $id, string $title, string $edited): array
    {
        return ['object' => 'page', 'id' => $id, 'url' => 'https://www.notion.so/'.$id, 'last_edited_time' => $edited, 'archived' => false, 'properties' => ['Name' => ['type' => 'title', 'title' => self::text($title)]]];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function block(string $id, string $type, array $data, bool $children = false): array
    {
        return ['object' => 'block', 'id' => $id, 'type' => $type, 'has_children' => $children, $type => $data];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return array<string, mixed>
     */
    private static function blocks(array $blocks): array
    {
        return ['results' => $blocks, 'has_more' => false, 'next_cursor' => null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function text(string $text): array
    {
        return [['plain_text' => $text, 'annotations' => []]];
    }
}
