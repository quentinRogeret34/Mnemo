<?php

namespace App\Entity;

use App\Enum\ConnectorMode;
use App\Enum\ConnectorTransport;
use App\Enum\MemoryType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An external MCP server and the way its content is imported into the graph.
 */
#[ORM\Entity]
class Connector
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Donner un nom au connecteur.')]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(length: 10, enumType: ConnectorTransport::class)]
    private ConnectorTransport $transport = ConnectorTransport::Http;

    /**
     * URL of an HTTP server, or command line of a STDIO server.
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Indiquer l\'URL ou la commande du serveur.')]
    private string $endpoint = '';

    /**
     * HTTP headers, or environment variables for STDIO. A value "env:NAME" is read from the environment at run time.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $credentials = [];

    #[ORM\Column(length: 20, enumType: ConnectorMode::class)]
    private ConnectorMode $mode = ConnectorMode::Resources;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tool = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $arguments = [];

    /**
     * Dot path to the list of items in the tool's JSON answer, e.g. "results"; empty when the answer is the list itself.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $itemsPath = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $textField = null;

    #[ORM\Column(length: 20, enumType: MemoryType::class)]
    private MemoryType $memoryType = MemoryType::Fact;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Folder $folder = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lastReport = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateTool(ExecutionContextInterface $context): void
    {
        if (ConnectorMode::Resources !== $this->mode && null === $this->tool) {
            $context->buildViolation('Indiquer l\'outil à appeler.')->atPath('tool')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getTransport(): ConnectorTransport
    {
        return $this->transport;
    }

    public function setTransport(ConnectorTransport $transport): void
    {
        $this->transport = $transport;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): void
    {
        $this->endpoint = trim($endpoint);
    }

    /**
     * @return array<string, string>
     */
    public function getCredentials(): array
    {
        return $this->credentials;
    }

    /**
     * @param array<string, string> $credentials
     */
    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function getMode(): ConnectorMode
    {
        return $this->mode;
    }

    public function setMode(ConnectorMode $mode): void
    {
        $this->mode = $mode;
    }

    public function getTool(): ?string
    {
        return $this->tool;
    }

    public function setTool(?string $tool): void
    {
        $this->tool = '' === trim((string) $tool) ? null : trim($tool);
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    public function getItemsPath(): ?string
    {
        return $this->itemsPath;
    }

    public function setItemsPath(?string $itemsPath): void
    {
        $this->itemsPath = '' === trim((string) $itemsPath) ? null : trim($itemsPath);
    }

    public function getTextField(): ?string
    {
        return $this->textField;
    }

    public function setTextField(?string $textField): void
    {
        $this->textField = '' === trim((string) $textField) ? null : trim($textField);
    }

    public function getMemoryType(): MemoryType
    {
        return $this->memoryType;
    }

    public function setMemoryType(MemoryType $memoryType): void
    {
        $this->memoryType = $memoryType;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function getFolder(): ?Folder
    {
        return $this->folder;
    }

    public function setFolder(?Folder $folder): void
    {
        $this->folder = $folder;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastReport(): ?array
    {
        return $this->lastReport;
    }

    /**
     * @param array<string, mixed> $report
     */
    public function recordRun(array $report): void
    {
        $this->lastRunAt = new \DateTimeImmutable();
        $this->lastReport = $report;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
