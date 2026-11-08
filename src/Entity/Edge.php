<?php

namespace App\Entity;

use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\EdgeStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'edge_unique', columns: ['source_id', 'target_id', 'relation'])]
#[ORM\Index(name: 'edge_target_idx', columns: ['target_id'])]
class Edge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: EdgeStatus::class)]
    private EdgeStatus $status = EdgeStatus::Active;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Node $source,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Node $target,
        #[ORM\Column(length: 50)]
        private string $relation,
        #[ORM\Column(length: 20, enumType: Confidence::class)]
        private Confidence $confidence,
        #[ORM\Column(length: 20, enumType: EdgeOrigin::class)]
        private EdgeOrigin $origin,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $evidence = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Node
    {
        return $this->source;
    }

    public function getTarget(): Node
    {
        return $this->target;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    public function getConfidence(): Confidence
    {
        return $this->confidence;
    }

    public function getOrigin(): EdgeOrigin
    {
        return $this->origin;
    }

    public function getEvidence(): ?string
    {
        return $this->evidence;
    }

    public function getStatus(): EdgeStatus
    {
        return $this->status;
    }

    public function reject(): void
    {
        $this->status = EdgeStatus::Rejected;
    }

    /**
     * A human restoring a link vouches for it.
     */
    public function restore(): void
    {
        $this->status = EdgeStatus::Active;
        $this->confidence = Confidence::Verified;
        $this->origin = EdgeOrigin::Human;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
