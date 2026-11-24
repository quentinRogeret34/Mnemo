<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[UniqueEntity('slug', message: 'project.slug.taken')]
#[UniqueEntity('repository', message: 'project.repository.taken')]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Remote of the repository as "host/owner/name", lower case: what identifies the project from any machine.
     */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $repository = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /**
     * Models of this project when they differ from those of the instance, e.g. "anthropic/claude-sonnet-4.5".
     */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $chatModel = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $embeddingModel = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $autoGarden = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAutoGardenAt = null;

    public function __construct(
        #[ORM\Column(length: 100, unique: true)]
        #[Assert\NotBlank(message: 'project.slug.blank')]
        #[Assert\Length(max: 100)]
        #[Assert\Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/', message: 'project.slug.format')]
        #[Assert\NotIdenticalTo('new', message: 'project.slug.reserved')]
        private string $slug,
        #[ORM\Column(length: 255)]
        #[Assert\NotBlank(message: 'project.name.blank')]
        #[Assert\Length(max: 255)]
        private string $name,
        #[ORM\Column(length: 1024)]
        #[Assert\Length(max: 1024)]
        private string $repositoryPath,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getRepositoryPath(): string
    {
        return $this->repositoryPath;
    }

    public function setRepositoryPath(string $repositoryPath): void
    {
        $this->repositoryPath = trim($repositoryPath);
    }

    public function getRepository(): ?string
    {
        return $this->repository;
    }

    public function setRepository(?string $repository): void
    {
        $this->repository = $repository;
    }

    public function getChatModel(): ?string
    {
        return $this->chatModel;
    }

    public function setChatModel(?string $chatModel): void
    {
        $this->chatModel = '' === trim((string) $chatModel) ? null : trim($chatModel);
    }

    public function getEmbeddingModel(): ?string
    {
        return $this->embeddingModel;
    }

    public function setEmbeddingModel(?string $embeddingModel): void
    {
        $this->embeddingModel = '' === trim((string) $embeddingModel) ? null : trim($embeddingModel);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function archive(): void
    {
        $this->archivedAt ??= new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->archivedAt = null;
    }

    public function isAutoGarden(): bool
    {
        return $this->autoGarden;
    }

    public function setAutoGarden(bool $autoGarden): void
    {
        $this->autoGarden = $autoGarden;
    }

    public function getLastAutoGardenAt(): ?\DateTimeImmutable
    {
        return $this->lastAutoGardenAt;
    }

    public function markAutoGardened(?\DateTimeImmutable $at = null): void
    {
        $this->lastAutoGardenAt = $at ?? new \DateTimeImmutable();
    }
}
