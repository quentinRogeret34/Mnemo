<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One tool call received by the MCP server, kept for the usage dashboard. Rows are written by McpCallRecorder.
 */
#[ORM\Entity]
#[ORM\Index(name: 'mcp_call_created_idx', columns: ['created_at'])]
class McpCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ApiKey $apiKey = null;

    /**
     * How the search tool matched: "keyword" or "hybrid".
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $mode = null;

    /**
     * @param list<string>|null $searchTypes node types asked to the search tool
     */
    public function __construct(
        #[ORM\Column(length: 50)]
        private string $tool,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $project = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $query = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $searchTypes = null,
        #[ORM\Column]
        private int $results = 0,
        #[ORM\Column]
        private int $memories = 0,
        #[ORM\Column]
        private bool $failed = false,
        #[ORM\Column]
        private int $durationMs = 0,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $client = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getProject(): ?string
    {
        return $this->project;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    /** @return list<string>|null */
    public function getSearchTypes(): ?array
    {
        return $this->searchTypes;
    }

    public function getResults(): int
    {
        return $this->results;
    }

    public function getMemories(): int
    {
        return $this->memories;
    }

    public function isFailed(): bool
    {
        return $this->failed;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getClient(): ?string
    {
        return $this->client;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function getApiKey(): ?ApiKey
    {
        return $this->apiKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
