<?php

namespace App\Connector;

use App\Document\DocumentStorage;
use App\Entity\Connector;
use App\Entity\Node;
use App\Enum\ConnectorMode;
use App\Memory\MemoryStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\ResourceDefinition;
use Symfony\Component\String\Slugger\AsciiSlugger;

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

    public function __construct(
        private readonly McpClientFactory $clientFactory,
        private readonly DocumentStorage $documentStorage,
        private readonly MemoryStore $memoryStore,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{server: string, tools: list<array{name: string, description: ?string}>, resources: list<array{uri: string, name: string, mime_type: ?string}>}
     */
    public function inspect(Connector $connector): array
    {
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
    public function sync(Connector $connector): array
    {
        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'error' => null];

        try {
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
        } catch (\Throwable $exception) {
            $report['error'] = $exception->getMessage();
        }

        $connector->recordRun($report);
        $this->entityManager->flush();

        return $report;
    }

    /**
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function importResources(Client $client, Connector $connector, array &$report): void
    {
        foreach ($this->resources($client) as $resource) {
            foreach ($client->readResource($resource->uri)->contents as $contents) {
                $title = $resource->title ?? $resource->name;
                $mimeType = $contents->mimeType ?? $resource->mimeType ?? 'text/plain';

                if ($contents instanceof TextResourceContents) {
                    $this->upsertDocument($connector, $resource->uri, $title, 'md', $this->markdown($title, $contents->text, $mimeType), $report);
                } elseif ($contents instanceof BlobResourceContents && isset(self::BLOB_FORMATS[$mimeType])) {
                    $this->upsertDocument($connector, $resource->uri, $title, self::BLOB_FORMATS[$mimeType], (string) base64_decode($contents->blob, true), $report);
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
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function importToolMemories(Client $client, Connector $connector, array &$report): void
    {
        $items = json_decode($this->callTool($client, $connector), true);
        foreach (array_filter(explode('.', (string) $connector->getItemsPath()), 'strlen') as $key) {
            $items = \is_array($items) ? ($items[$key] ?? null) : null;
        }
        if (!\is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException(\sprintf('La réponse de l\'outil ne contient pas de liste au chemin "%s".', $connector->getItemsPath() ?? ''));
        }

        foreach (\array_slice($items, 0, self::MAX_ITEMS) as $index => $item) {
            $text = \is_string($item) ? $item : $this->itemText($item, $connector->getTextField());
            if (null === $text || '' === trim($text) || mb_strlen(trim($text)) > 1000) {
                $report['skipped'][] = \sprintf('élément %d (texte absent ou de plus de 1000 caractères)', $index);
                continue;
            }

            $memory = $this->memoryStore->findByText($text);
            if (null === $memory) {
                $memory = $this->memoryStore->add($text, $connector->getMemoryType(), 'connector', ['connector' => $connector->getName()]);
                ++$report['created'];
            } else {
                ++$report['unchanged'];
            }
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
        $origin = 'connector:'.$connector->getId().':'.$key;
        $hash = hash('sha256', $content);
        $id = $this->connection->fetchOne("SELECT id FROM node WHERE type = 'document' AND metadata->>'origin' = :origin", ['origin' => $origin]);
        $document = false === $id ? null : $this->entityManager->find(Node::class, $id);

        if (null !== $document && $hash === $document->getContentHash()) {
            ++$report['unchanged'];

            return;
        }

        $file = tempnam(sys_get_temp_dir(), 'connector');
        file_put_contents($file, $content);
        try {
            if (null === $document) {
                $filename = (new AsciiSlugger())->slug($title)->lower()->toString() ?: 'document';
                $document = $this->documentStorage->store($connector->getFolder(), new \SplFileInfo($file), $filename.'.'.$format, ['source' => 'connector', 'connector' => $connector->getName(), 'origin' => $origin]);
                if ('md' !== $format) {
                    $document->update($title, $document->getPath(), null, $document->getMetadata());
                }
                ++$report['created'];
            } else {
                $this->documentStorage->replace($document, new \SplFileInfo($file));
                ++$report['updated'];
            }
        } finally {
            unlink($file);
        }

        $document->setContentHash($hash);
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
