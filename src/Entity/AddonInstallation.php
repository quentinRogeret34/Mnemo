<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persisted enable/disable state and JSON configuration for an Addon, either workspace-wide (project = null)
 * or overridden for a specific Project.
 */
#[ORM\Entity]
#[ORM\Table(name: 'addon_installation')]
#[ORM\Index(name: 'addon_installation_addon_idx', columns: ['addon_id'])]
#[ORM\Index(name: 'addon_installation_project_idx', columns: ['project_id'])]
class AddonInstallation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $addonId;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $config = [];

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $addonId,
        ?Project $project = null,
        bool $enabled = true,
        array $config = [],
    ) {
        $this->addonId = $addonId;
        $this->project = $project;
        $this->enabled = $enabled;
        $this->config = $config;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAddonId(): string
    {
        return $this->addonId;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
