<?php

namespace App\Entity;

use App\Enum\SwarmMessageKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message posted on a SwarmRun's shared coordination board (planner, workers, reviewer or human).
 */
#[ORM\Entity]
#[ORM\Table(name: 'swarm_message')]
#[ORM\Index(name: 'swarm_message_swarm_created_idx', columns: ['swarm_id', 'created_at'])]
class SwarmMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SwarmRun $swarm,
        #[ORM\Column(length: 100)]
        private string $author,
        #[ORM\Column(length: 20, enumType: SwarmMessageKind::class)]
        private SwarmMessageKind $kind,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?AgentSession $session = null,
    ) {
        $this->author = mb_substr(trim($author) ?: 'system', 0, 100);
        $this->body = trim($body);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSwarm(): SwarmRun
    {
        return $this->swarm;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getKind(): SwarmMessageKind
    {
        return $this->kind;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSession(): ?AgentSession
    {
        return $this->session;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
