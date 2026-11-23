<?php

namespace App\Mcp;

use App\Security\ProjectScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records every MCP tool call for the usage dashboard.
 * Rows are inserted with DBAL, not the entity manager: flushing here would also write whatever a failed tool left pending.
 */
final class McpCallRecorder implements ResetInterface
{
    private const SEARCH_TYPES = ['memory', 'document', 'business_rule', 'code_file'];

    /** @var array<string|int, float> */
    private array $startedAt = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[AsEventListener]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->getRequest() instanceof CallToolRequest) {
            $this->startedAt[$event->getRequest()->getId()] = microtime(true);
        }
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        $result = $event->getResponse()->result;
        $this->record($event->getRequest(), $event->getSession(), $result instanceof CallToolResult ? $result : null, false);
    }

    #[AsEventListener]
    public function onError(ErrorEvent $event): void
    {
        $this->record($event->getRequest(), $event->getSession(), null, true);
    }

    public function reset(): void
    {
        $this->startedAt = [];
    }

    private function record(Request $request, SessionInterface $session, ?CallToolResult $result, bool $failed): void
    {
        if (!$request instanceof CallToolRequest) {
            return;
        }

        $arguments = $request->arguments;
        $payload = $this->payload($result);
        $project = $arguments['project'] ?? ($arguments['projects'][0] ?? null);
        $query = $arguments['query'] ?? ($arguments['path'] ?? null);
        $started = $this->startedAt[$request->getId()] ?? null;
        unset($this->startedAt[$request->getId()]);

        try {
            $this->connection->insert('mcp_call', [
                'tool' => mb_substr($request->name, 0, 50),
                'project' => \is_string($project) ? mb_substr($project, 0, 100) : null,
                'query' => \is_string($query) ? mb_substr($query, 0, 255) : null,
                'search_types' => 'search' === $request->name ? array_values(array_intersect(self::SEARCH_TYPES, (array) ($arguments['types'] ?? self::SEARCH_TYPES))) : null,
                'results' => \count($payload['results'] ?? []),
                'memories' => $this->memories($request->name, $payload),
                'failed' => $failed || true === $result?->isError,
                'duration_ms' => null === $started ? 0 : (int) round((microtime(true) - $started) * 1000),
                'client' => \is_string($client = $session->get('client_info')['name'] ?? null) ? mb_substr($client, 0, 100) : null,
                'api_key_id' => $this->projectScope->key()?->getId(),
                'mode' => \is_string($payload['mode'] ?? null) ? mb_substr($payload['mode'], 0, 10) : null,
                'created_at' => new \DateTimeImmutable(),
            ], ['search_types' => Types::JSON, 'failed' => Types::BOOLEAN, 'created_at' => Types::DATETIME_IMMUTABLE]);
        } catch (\Throwable $exception) {
            $this->logger->warning('MCP call not recorded: {message}', ['message' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?CallToolResult $result): array
    {
        if (\is_array($result?->structuredContent)) {
            return $result->structuredContent;
        }
        $first = $result?->content[0] ?? null;
        $decoded = $first instanceof TextContent ? json_decode((string) $first->text, true) : null;

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Memories handed back to the agent: the "recalled" figure of the dashboard.
     *
     * @param array<string, mixed> $payload
     */
    private function memories(string $tool, array $payload): int
    {
        return match ($tool) {
            'search' => \count(array_filter($payload['results'] ?? [], static fn (mixed $row): bool => 'memory' === ($row['type'] ?? null))),
            'file_context', 'get_document' => \count($payload['memories'] ?? []),
            default => 0,
        };
    }
}
