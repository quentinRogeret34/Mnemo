<?php

namespace App\Entity;

use App\Enum\NodeType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'node_external_id_unique', columns: ['project_id', 'type', 'external_id'])]
#[ORM\Index(name: 'node_path_idx', columns: ['project_id', 'path'])]
class Node
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Folder $folder = null;

    /**
     * Projects a workspace-level node (a document) is attached to.
     *
     * @var Collection<int, Project>
     */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    #[ORM\JoinTable(name: 'node_project')]
    private Collection $projects;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?Project $project,
        #[ORM\Column(length: 30, enumType: NodeType::class)]
        private NodeType $type,
        #[ORM\Column(type: Types::TEXT)]
        private string $externalId,
        #[ORM\Column(type: Types::TEXT)]
        private string $label,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $path = null,
        #[ORM\Column(nullable: true)]
        private ?int $line = null,
        #[ORM\Column(type: Types::JSON)]
        private array $metadata = [],
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->projects = new ArrayCollection();
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return bool whether anything changed
     */
    public function update(string $label, ?string $path, ?int $line, array $metadata): bool
    {
        if ($label === $this->label && $path === $this->path && $line === $this->line && $metadata === $this->metadata) {
            return false;
        }

        $this->label = $label;
        $this->path = $path;
        $this->line = $line;
        $this->metadata = $metadata;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    /**
     * For annotations computed by the system (verification, analysis): they are not an edit of the node, so the
     * modification date, which tells when an annotation is stale, stays as it is.
     *
     * @param array<string, mixed> $metadata
     */
    public function annotate(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getFolder(): ?Folder
    {
        return $this->folder;
    }

    public function moveTo(?Folder $folder): void
    {
        $this->folder = $folder;
    }

    /**
     * @return Collection<int, Project>
     */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): void
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
        }
    }

    public function removeProject(Project $project): void
    {
        $this->projects->removeElement($project);
    }

    public function getType(): NodeType
    {
        return $this->type;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): void
    {
        $this->contentHash = $contentHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
