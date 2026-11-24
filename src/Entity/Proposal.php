<?php

namespace App\Entity;

use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A change suggested by the LLM (new memory, duplicate, contradiction...) that a human applies or rejects.
 */
#[ORM\Entity]
#[ORM\Index(name: 'proposal_status_idx', columns: ['status'])]
#[ORM\Index(name: 'proposal_status_project_idx', columns: ['status', 'project_id'])]
class Proposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ProposalStatus::class)]
    private ProposalStatus $status = ProposalStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Project $project,
        #[ORM\Column(length: 20, enumType: ProposalKind::class)]
        private ProposalKind $kind,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $payload,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getKind(): ProposalKind
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return ProposalStatus::Pending === $this->status;
    }

    public function decide(ProposalStatus $status): void
    {
        $this->status = $status;
        $this->decidedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function annotatePayload(array $extra): void
    {
        $this->payload = $extra + $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
