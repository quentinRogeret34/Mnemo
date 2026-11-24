<?php

namespace App\Entity;

use App\Enum\AgentRunnerKind;
use App\Enum\AgentSessionStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One tracked agent execution session (IDE/CLI external run via MCP/hooks, or orchestrated worker run in a swarm).
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_session')]
#[ORM\UniqueConstraint(name: 'agent_session_public_id_uidx', columns: ['public_id'])]
#[ORM\Index(name: 'agent_session_project_started_idx', columns: ['project_id', 'started_at'])]
#[ORM\Index(name: 'agent_session_status_idx', columns: ['status'])]
class AgentSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $publicId;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $goal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $client = null;

    #[ORM\Column(length: 20, enumType: AgentSessionStatus::class)]
    private AgentSessionStatus $status;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $branch = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $worktreePath = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sourceRuleCode = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $startedBy = null;

    #[ORM\ManyToOne(targetEntity: AgentSession::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AgentSession $parentSession = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $filesTouched = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $metadata = [];

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastHeartbeatAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(length: 30, enumType: AgentRunnerKind::class)]
        private AgentRunnerKind $runner = AgentRunnerKind::ExternalMcp,
        #[ORM\Column(length: 60)]
        private string $role = 'developer',
        AgentSessionStatus $initialStatus = AgentSessionStatus::Running,
        ?string $publicId = null,
    ) {
        $now = new \DateTimeImmutable();
        $this->publicId = $publicId ?? 'sess_'.bin2hex(random_bytes(8));
        $this->title = mb_substr(trim($title), 0, 255);
        $this->role = mb_substr(trim($role) ?: 'developer', 0, 60);
        $this->status = $initialStatus;
        $this->startedAt = $now;
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

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getGoal(): ?string
    {
        return $this->goal;
    }

    public function setGoal(?string $goal): void
    {
        $trimmed = null === $goal ? null : trim($goal);
        $this->goal = '' === $trimmed ? null : $trimmed;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getRunner(): AgentRunnerKind
    {
        return $this->runner;
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

    public function getStatus(): AgentSessionStatus
    {
        return $this->status;
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

    public function getWorktreePath(): ?string
    {
        return $this->worktreePath;
    }

    public function setWorktreePath(?string $worktreePath): void
    {
        $trimmed = null === $worktreePath ? null : mb_substr(trim($worktreePath), 0, 1024);
        $this->worktreePath = '' === $trimmed ? null : $trimmed;
    }

    public function getSourceRuleCode(): ?string
    {
        return $this->sourceRuleCode;
    }

    public function setSourceRuleCode(?string $sourceRuleCode): void
    {
        $trimmed = null === $sourceRuleCode ? null : mb_substr(trim($sourceRuleCode), 0, 50);
        $this->sourceRuleCode = '' === $trimmed ? null : $trimmed;
    }

    public function getStartedBy(): ?string
    {
        return $this->startedBy;
    }

    public function setStartedBy(?string $startedBy): void
    {
        $trimmed = null === $startedBy ? null : mb_substr(trim($startedBy), 0, 150);
        $this->startedBy = '' === $trimmed ? null : $trimmed;
    }

    public function getParentSession(): ?AgentSession
    {
        return $this->parentSession;
    }

    public function setParentSession(?AgentSession $parentSession): void
    {
        $this->parentSession = $parentSession;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * @return list<string>
     */
    public function getFilesTouched(): array
    {
        return $this->filesTouched;
    }

    /**
     * @param list<string> $files
     */
    public function mergeFilesTouched(array $files): void
    {
        $clean = array_values(array_filter(array_map(
            static fn (string $file): string => trim($file),
            $files,
        ), static fn (string $file): bool => '' !== $file));

        if ([] === $clean) {
            return;
        }

        $this->filesTouched = array_values(array_unique([...$this->filesTouched, ...$clean]));
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function mergeMetadata(array $patch): void
    {
        if ([] === $patch) {
            return;
        }

        $this->metadata = array_merge($this->metadata, $patch);
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getLastHeartbeatAt(): \DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function touch(?\DateTimeImmutable $at = null): void
    {
        $this->lastHeartbeatAt = $at ?? new \DateTimeImmutable();
    }

    public function markRunning(): void
    {
        $this->status = AgentSessionStatus::Running;
        $this->finishedAt = null;
        $this->touch();
    }

    public function markWaitingHuman(): void
    {
        $this->status = AgentSessionStatus::WaitingHuman;
        $this->touch();
    }

    /**
     * @param list<string> $filesTouched
     */
    public function finish(AgentSessionStatus $status, ?string $summary = null, array $filesTouched = []): void
    {
        if (!$status->isTerminal()) {
            throw new \InvalidArgumentException(\sprintf('Status "%s" is not terminal.', $status->value));
        }

        $now = new \DateTimeImmutable();
        $this->status = $status;
        if (null !== $summary && '' !== trim($summary)) {
            $this->summary = trim($summary);
        }
        $this->mergeFilesTouched($filesTouched);
        $this->lastHeartbeatAt = $now;
        $this->finishedAt = $now;
    }

    public function durationSeconds(?\DateTimeImmutable $reference = null): int
    {
        $end = $this->finishedAt ?? $reference ?? new \DateTimeImmutable();

        return max(0, $end->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
