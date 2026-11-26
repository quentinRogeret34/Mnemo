<?php

namespace App\Entity;

use App\Enum\SwarmNodeStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One external client (Claude Code, Cursor, Codex, Antigravity, curl…) connected to an interactive Swarm Hub.
 */
#[ORM\Entity]
#[ORM\Table(name: 'swarm_node')]
#[ORM\UniqueConstraint(name: 'swarm_node_public_id_uidx', columns: ['public_id'])]
#[ORM\Index(name: 'swarm_node_swarm_status_idx', columns: ['swarm_id', 'status'])]
#[ORM\Index(name: 'swarm_node_swarm_heartbeat_idx', columns: ['swarm_id', 'last_heartbeat_at'])]
class SwarmNode
{
    private const STALE_AFTER_SECONDS = 45;
    private const OFFLINE_AFTER_SECONDS = 180;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $publicId;

    #[ORM\Column(length: 20, enumType: SwarmNodeStatus::class, options: ['default' => 'idle'])]
    private SwarmNodeStatus $status = SwarmNodeStatus::Idle;

    #[ORM\Column]
    private \DateTimeImmutable $lastHeartbeatAt;

    #[ORM\Column]
    private \DateTimeImmutable $connectedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $disconnectedAt = null;

    /**
     * @param array<int|string, mixed> $capabilities
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SwarmRun $swarm,
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $client = null,
        #[ORM\Column(length: 60, options: ['default' => 'developer'])]
        private string $role = 'developer',
        #[ORM\Column(length: 200, nullable: true)]
        private ?string $branch = null,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
        private array $capabilities = [],
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?AgentSession $session = null,
        ?string $publicId = null,
    ) {
        $now = new \DateTimeImmutable();
        $this->publicId = $publicId ?? 'node_'.bin2hex(random_bytes(6));
        $this->name = mb_substr(trim($name) ?: 'node', 0, 100);
        $this->setClient($client);
        $this->setRole($role);
        $this->setBranch($branch);
        $this->connectedAt = $now;
        $this->lastHeartbeatAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getSwarm(): SwarmRun
    {
        return $this->swarm;
    }

    public function getSession(): ?AgentSession
    {
        return $this->session;
    }

    public function setSession(?AgentSession $session): void
    {
        $this->session = $session;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getClient(): ?string
    {
        return $this->client;
    }

    public function setClient(?string $client): void
    {
        $trimmed = null === $client ? null : mb_substr(trim($client), 0, 100);
        $this->client = '' === $trimmed ? null : $trimmed;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = mb_substr(trim($role) ?: 'developer', 0, 60);
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }

    public function setBranch(?string $branch): void
    {
        $trimmed = null === $branch ? null : mb_substr(trim($branch), 0, 200);
        $this->branch = '' === $trimmed ? null : $trimmed;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @param array<int|string, mixed> $capabilities
     */
    public function setCapabilities(array $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    public function getStatus(): SwarmNodeStatus
    {
        return $this->status;
    }

    public function getLastHeartbeatAt(): \DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function getDisconnectedAt(): ?\DateTimeImmutable
    {
        return $this->disconnectedAt;
    }

    public function heartbeat(?\DateTimeImmutable $at = null): void
    {
        $this->lastHeartbeatAt = $at ?? new \DateTimeImmutable();
        if (SwarmNodeStatus::Offline === $this->status) {
            $this->status = SwarmNodeStatus::Idle;
            $this->disconnectedAt = null;
        }
    }

    public function markIdle(?\DateTimeImmutable $at = null): void
    {
        $this->status = SwarmNodeStatus::Idle;
        $this->disconnectedAt = null;
        $this->lastHeartbeatAt = $at ?? new \DateTimeImmutable();
    }

    public function markBusy(?\DateTimeImmutable $at = null): void
    {
        $this->status = SwarmNodeStatus::Busy;
        $this->disconnectedAt = null;
        $this->lastHeartbeatAt = $at ?? new \DateTimeImmutable();
    }

    public function disconnect(?\DateTimeImmutable $at = null): void
    {
        $now = $at ?? new \DateTimeImmutable();
        $this->status = SwarmNodeStatus::Offline;
        $this->disconnectedAt = $now;
        $this->lastHeartbeatAt = $now;
    }

    public function effectiveStatus(?\DateTimeImmutable $now = null): string
    {
        if (SwarmNodeStatus::Offline === $this->status || null !== $this->disconnectedAt) {
            return SwarmNodeStatus::Offline->value;
        }

        $reference = $now ?? new \DateTimeImmutable();
        $age = $reference->getTimestamp() - $this->lastHeartbeatAt->getTimestamp();

        if ($age > self::OFFLINE_AFTER_SECONDS) {
            return SwarmNodeStatus::Offline->value;
        }

        if ($age > self::STALE_AFTER_SECONDS) {
            return 'stale';
        }

        return $this->status->value;
    }

    public function isOnline(?\DateTimeImmutable $now = null): bool
    {
        return SwarmNodeStatus::Offline->value !== $this->effectiveStatus($now);
    }
}
