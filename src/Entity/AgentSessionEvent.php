<?php

namespace App\Entity;

use App\Enum\AgentSessionEventKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One chronological timeline entry recorded during an AgentSession.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_session_event')]
#[ORM\Index(name: 'agent_session_event_session_created_idx', columns: ['session_id', 'created_at'])]
class AgentSessionEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private AgentSession $session,
        #[ORM\Column(length: 30, enumType: AgentSessionEventKind::class)]
        private AgentSessionEventKind $kind,
        #[ORM\Column(type: Types::TEXT)]
        private string $message,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
        private array $payload = [],
    ) {
        $this->message = trim($message);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): AgentSession
    {
        return $this->session;
    }

    public function getKind(): AgentSessionEventKind
    {
        return $this->kind;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
