<?php

namespace App\Connector;

use App\Document\DocumentStorage;
use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Enum\ConnectorMode;
use App\Forge\ForgeImporter;
use App\Memory\MemoryStore;
use App\Notion\NotionClient;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\ResourceDefinition;
use Symfony\Component\Lock\LockFactory;

/**
 * Imports the content of an external MCP server into the graph. A run is idempotent: an item is identified by
 * its origin (connector + resource URI or tool) and only rewritten when its content hash changes.
 * Items that disappeared from the source are left untouched for now.
 */
final class ConnectorRunner
{
    private const MAX_ITEMS = 500;
    private const BLOB_FORMATS = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    /**
     * Types detected in the bytes that each format accepts, as for an upload: the declared mimeType is not trusted.
     */
    private const BLOB_CONTENT_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];

    public function __construct(
        private readonly McpClientFactory $clientFactory,
        private readonly DocumentStorage $documentStorage,
        private readonly MemoryStore $memoryStore,
        private readonly EntityManagerInterface $entityManager,
        private readonly ForgeImporter $forgeImporter,
        private readonly LockFactory $lockFactory,
        private readonly NotionClient $notion,
    ) {
    }

    /**
     * @return array{server: string, tools: list<array{name: string, description: ?string}>, resources: list<array{uri: string, name: string, mime_type: ?string}>}
     */
    public function inspect(Connector $connector): array
    {
        if ($connector->getMode()->isForge()) {
            return ['server' => $this->forgeImporter->describe($connector), 'tools' => [], 'resources' => []];
        }
        if (ConnectorMode::Notion === $connector->getMode()) {
            return [
                'server' => $this->notion->describe($connector),
                'tools' => [],
                'resources' => array_map(
                    static fn (array $page): array => ['uri' => $page['url'], 'name' => $page['title'], 'mime_type' => 'page'],
                    iterator_to_array($this->notion->pages($connector, limit: 20), false),
                ),
            ];
        }

        $client = $this->clientFactory->connect($connector);
        try {
            $server = $client->getServerInfo();
            $capabilities = $client->getServerCapabilities();

            return [
                'server' => null === $server ? 'inconnu' : $server->name.' '.$server->version,
                'tools' => false === $capabilities?->tools ? [] : array_map(
                    static fn ($tool): array => ['name' => $tool->name, 'description' => $tool->description],
                    $client->listTools()->tools,
                ),
                'resources' => false === $capabilities?->resources ? [] : array_map(
                    static fn (ResourceDefinition $resource): array => ['uri' => $resource->uri, 'name' => $resource->title ?? $resource->name, 'mime_type' => $resource->mimeType],
                    $this->resources($client),
                ),
            ];
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string}
     */
    public function sync(Connector $connector, string $trigger = ConnectorRun::MANUAL): array
    {
        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'error' => null];
        $lock = $this->lockFactory->createLock('connector-sync-'.$connector->getId());
        if (!$lock->acquire()) {
            return ['error' => 'Une synchronisation de ce connecteur est déjà en cours.'] + $report;
        }
        $startedAt = new \DateTimeImmutable();

        try {
            if ($connector->getMode()->isForge()) {
                $this->forgeImporter->import($connector, $report);
            } elseif (ConnectorMode::Notion === $connector->getMode()) {
                $this->importNotion($connector, $report);
            } else {
                $client = $this->clientFactory->connect($connector);
                try {
                    match ($connector->getMode()) {
                        ConnectorMode::Resources => $this->importResources($client, $connector, $report),
                        ConnectorMode::ToolDocument => $this->importToolDocument($client, $connector, $report),
                        ConnectorMode::ToolMemories => $this->importToolMemories($client, $connector, $report),
                    };
                } finally {
                    $client->disconnect();
                }
            }
        } catch (\Throwable $exception) {
            $report['error'] = $exception->getMessage();
        }

        try {
            $connector->recordRun($report);
            $this->entityManager->persist(new ConnectorRun($connector, $trigger, $startedAt, $report));
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return $report;
    }

    /**
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function importResources(Client $client, Connector $connector, array &$report): void
    {
        $seen = [];
        foreach ($this->resources($client) as $resource) {
            foreach ($client->readResource($resource->uri)->contents as $contents) {
                $title = $resource->title ?? $resource->name;
                $mimeType = $contents->mimeType ?? $resource->mimeType ?? 'text/plain';

                // Documents created in this run are not flushed yet, so the lookup by origin cannot see them.
                if (isset($seen[$contents->uri])) {
                    $report['skipped'][] = \sprintf('%s (contenu en double)', $contents->uri);
                    continue;
                }
                $seen[$contents->uri] = true;

                if ($contents instanceof TextResourceContents) {
                    $this->upsertDocument($connector, $contents->uri, $title, 'md', $this->markdown($title, $contents->text, $mimeType), $report);
                } elseif ($contents instanceof BlobResourceContents && isset(self::BLOB_FORMATS[$mimeType])) {
                    $format = self::BLOB_FORMATS[$mimeType];
                    $bytes = (string) base64_decode($contents->blob, true);
                    if (!\in_array((new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes), self::BLOB_CONTENT_TYPES[$format], true)) {
                        $report['skipped'][] = \sprintf('%s (le contenu ne correspond pas au format %s)', $contents->uri, $mimeType);
                        continue;
                    }
                    $this->upsertDocument($connector, $contents->uri, $title, $format, $bytes, $report);
                } else {
                    $report['skipped'][] = \sprintf('%s (format %s non pris en charge)', $resource->uri, $mimeType);
                }
            }
        }
    }

    /**
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function importToolDocument(Client $client, Connector $connector, array &$report): void
    {
        $text = $this->callTool($client, $connector);
        $title = $connector->getName();

        // The answer of a tool often starts with a heading of its own; the connector name is the document title.
        $body = json_validate($text) ? "```json\n".$text."\n```" : $text;
        $this->upsertDocument($connector, 'tool:'.$connector->getTool(), $title, 'md', '# '.$title."\n\n".$body, $report);
    }

    /**
     * A page is one document. Only the pages edited since the previous run are read again.
     *
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function importNotion(Connector $connector, array &$report): void
    {
        $cursor = $connector->getCursor();
        $latest = $cursor['pages'] ?? null;
        foreach ($this->notion->pages($connector, $cursor['pages'] ?? null) as $page) {
            try {
                $body = $this->notion->markdown($connector, $page['id']);
            } catch (\RuntimeException $exception) {
                $report['skipped'][] = \sprintf('%s (%s)', $page['title'], $exception->getMessage());
                continue;
            }
            $this->upsertDocument($connector, 'notion:'.$page['id'], $page['title'], 'md', '# '.$page['title']."\n\n".$body, $report);
            $latest = max($latest ?? '', $page['edited']);
        }
        $connector->setCursor(['pages' => $latest] + $cursor);
    }

    /**
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function importToolMemories(Client $client, Connector $connector, array &$report): void
    {
        $items = json_decode($this->callTool($client, $connector), true);
        foreach (array_filter(explode('.', (string) $connector->getItemsPath()), static fn (string $value): bool => '' !== $value) as $key) {
            $items = \is_array($items) ? ($items[$key] ?? null) : null;
        }
        if (!\is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException(\sprintf('La réponse de l\'outil ne contient pas de liste au chemin "%s".', $connector->getItemsPath() ?? ''));
        }

        // Memories added in this run are not flushed yet, so findByText cannot see them.
        $seen = [];
        foreach (\array_slice($items, 0, self::MAX_ITEMS) as $index => $item) {
            $text = \is_string($item) ? $item : $this->itemText($item, $connector->getTextField());
            if (null === $text || '' === trim($text) || mb_strlen(trim($text)) > 1000) {
                $report['skipped'][] = \sprintf('élément %d (texte absent ou de plus de 1000 caractères)', $index);
                continue;
            }

            $key = mb_strtolower(trim($text));
            $memory = $seen[$key] ?? $this->memoryStore->findByText($text);
            if (null === $memory) {
                $memory = $this->memoryStore->add($text, $connector->getMemoryType(), 'connector', ['connector' => $connector->getName()]);
                ++$report['created'];
            } else {
                ++$report['unchanged'];
            }
            $seen[$key] = $memory;
            if (null !== $connector->getProject()) {
                $memory->addProject($connector->getProject());
            }
        }
    }

    /**
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function upsertDocument(Connector $connector, string $key, string $title, string $format, string $content, array &$report): void
    {
        [$document, $status] = $this->documentStorage->upsertContent(
            'connector:'.$connector->getId().':'.$key,
            $connector->getFolder(),
            $content,
            $title,
            $format,
            ['source' => 'connector', 'connector' => $connector->getName()],
        );
        ++$report[$status];
        if ('unchanged' === $status) {
            return;
        }

        if (null !== $connector->getProject()) {
            $document->addProject($connector->getProject());
        }
    }

    private function callTool(Client $client, Connector $connector): string
    {
        $result = $client->callTool((string) $connector->getTool(), $connector->getArguments());
        $text = implode("\n\n", array_map(
            static fn (TextContent $content): string => $content->text,
            array_filter($result->content, static fn ($content): bool => $content instanceof TextContent),
        ));

        if ($result->isError) {
            throw new \RuntimeException(\sprintf('L\'outil "%s" a renvoyé une erreur : %s', $connector->getTool(), mb_substr($text, 0, 500)));
        }

        return $text;
    }

    /**
     * @return list<ResourceDefinition>
     */
    private function resources(Client $client): array
    {
        $resources = [];
        $cursor = null;
        do {
            $page = $client->listResources($cursor);
            $resources = [...$resources, ...$page->resources];
            $cursor = $page->nextCursor;
        } while (null !== $cursor && \count($resources) < self::MAX_ITEMS);

        return \array_slice($resources, 0, self::MAX_ITEMS);
    }

    private function markdown(string $title, string $text, string $mimeType): string
    {
        $body = str_contains($mimeType, 'json') ? "```json\n".$text."\n```" : $text;

        return preg_match('/\A\s*#\s+/', $body) ? $body : '# '.$title."\n\n".$body;
    }

    private function itemText(mixed $item, ?string $field): ?string
    {
        if (!\is_array($item)) {
            return null;
        }
        foreach (null === $field ? ['memory', 'text', 'content'] : [$field] as $candidate) {
            if (isset($item[$candidate]) && \is_string($item[$candidate])) {
                return $item[$candidate];
            }
        }

        return null;
    }
}
