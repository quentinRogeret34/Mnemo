<?php

namespace App\Entity;

use App\Enum\AgentAssetKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A skill, slash command or subagent kept with the project and written into the ".claude" directory of every
 * Claude Code connected to it.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'agent_asset_unique', columns: ['project_id', 'kind', 'name'])]
#[UniqueEntity(['project', 'kind', 'name'], message: 'Ce nom est déjà pris pour ce type dans le projet.', errorPath: 'name')]
class AgentAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: AgentAssetKind::class)]
    private AgentAssetKind $kind = AgentAssetKind::Skill;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'Donner un nom.')]
    #[Assert\Length(max: 64)]
    #[Assert\Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/', message: 'Minuscules, chiffres et tirets seulement, ex. « revue-de-pr » : c\'est le nom du fichier.')]
    private string $name = '';

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank(message: 'Dire quand l\'utiliser : Claude choisit d\'après cette description.')]
    #[Assert\Length(max: 500)]
    private string $description = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Écrire les instructions.')]
    #[Assert\Length(max: 100000)]
    private string $content = '';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getKind(): AgentAssetKind
    {
        return $this->kind;
    }

    public function setKind(AgentAssetKind $kind): void
    {
        $this->kind = $kind;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = trim((string) $name);
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = trim((string) $description);
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(?string $content): void
    {
        $this->content = trim((string) $content);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function path(): string
    {
        return $this->kind->path($this->name);
    }

    /**
     * The file as Claude Code reads it. Instructions that bring their own front matter (to set allowed tools,
     * a model...) are kept as they are; the others get the name and the description as front matter.
     */
    public function file(): string
    {
        if (str_starts_with($this->content, "---\n")) {
            return $this->content."\n";
        }
        $description = json_encode($this->description, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \sprintf("---\n%sdescription: %s\n---\n\n%s\n", AgentAssetKind::Command === $this->kind ? '' : 'name: '.$this->name."\n", $description, $this->content);
    }
}
