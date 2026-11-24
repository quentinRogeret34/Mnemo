<?php

namespace App\Entity;

use App\Enum\AgentRunnerKind;
use App\Enum\SwarmStatus;
use App\Enum\SwarmStrategy;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One multi-agent swarm run orchestrated by Mnemo's Software Factory:
 * Plan (internal LLM) -> Isolated Workers (CLI in git worktree or internal LLM) -> Review (internal LLM).
 */
#[ORM\Entity]
#[ORM\Table(name: 'swarm_run')]
#[ORM\UniqueConstraint(name: 'swarm_run_public_id_uidx', columns: ['public_id'])]
#[ORM\Index(name: 'swarm_run_project_created_idx', columns: ['project_id', 'created_at'])]
#[ORM\Index(name: 'swarm_run_status_idx', columns: ['status'])]
class SwarmRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $publicId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AgentSession $coordinatorSession = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sourceRuleCode = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $baseBranch = null;

    #[ORM\Column(length: 30, enumType: SwarmStatus::class)]
    private SwarmStatus $status = SwarmStatus::Planning;

    #[ORM\Column(options: ['default' => true])]
    private bool $requirePlanApproval = true;

    /**
     * Structured worker tasks produced by SwarmPlanner.
     *
     * @var list<array{slug: string, title: string, role: string, instructions: string, files_hint: list<string>}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $plan = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewSummary = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $goal,
        #[ORM\Column(length: 20, enumType: SwarmStrategy::class)]
        private SwarmStrategy $strategy = SwarmStrategy::Parallel,
        #[ORM\Column(length: 30, enumType: AgentRunnerKind::class)]
        private AgentRunnerKind $workerRunner = AgentRunnerKind::ClaudeCli,
        bool $requirePlanApproval = true,
        ?string $publicId = null,
    ) {
        $now = new \DateTimeImmutable();
        $this->publicId = $publicId ?? 'swarm_'.bin2hex(random_bytes(6));
        $this->title = mb_substr(trim($title), 0, 255);
        $this->goal = trim($goal);
        $this->requirePlanApproval = $requirePlanApproval;
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getCoordinatorSession(): ?AgentSession
    {
        return $this->coordinatorSession;
    }

    public function setCoordinatorSession(?AgentSession $coordinatorSession): void
    {
        $this->coordinatorSession = $coordinatorSession;
        $this->touch();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getGoal(): string
    {
        return $this->goal;
    }

    public function getStrategy(): SwarmStrategy
    {
        return $this->strategy;
    }

    public function getWorkerRunner(): AgentRunnerKind
    {
        return $this->workerRunner;
    }

    public function getStatus(): SwarmStatus
    {
        return $this->status;
    }

    public function requiresPlanApproval(): bool
    {
        return $this->requirePlanApproval;
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

    public function getBaseBranch(): ?string
    {
        return $this->baseBranch;
    }

    public function setBaseBranch(?string $baseBranch): void
    {
        $trimmed = null === $baseBranch ? null : mb_substr(trim($baseBranch), 0, 200);
        $this->baseBranch = '' === $trimmed ? null : $trimmed;
    }

    /**
     * @return list<array{slug: string, title: string, role: string, instructions: string, files_hint: list<string>}>
     */
    public function getPlan(): array
    {
        return $this->plan;
    }

    /**
     * @param list<array{slug: string, title: string, role: string, instructions: string, files_hint: list<string>}> $plan
     */
    public function setPlan(array $plan): void
    {
        $this->plan = $plan;
        $this->touch();
    }

    public function getReviewSummary(): ?string
    {
        return $this->reviewSummary;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): void
    {
        $trimmed = null === $createdBy ? null : mb_substr(trim($createdBy), 0, 150);
        $this->createdBy = '' === $trimmed ? null : $trimmed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markAwaitingApproval(): void
    {
        $this->status = SwarmStatus::AwaitingApproval;
        $this->touch();
    }

    public function markRunning(): void
    {
        $this->status = SwarmStatus::Running;
        $this->finishedAt = null;
        $this->touch();
    }

    public function markReviewing(): void
    {
        $this->status = SwarmStatus::Reviewing;
        $this->touch();
    }

    public function pause(): void
    {
        if ($this->status->isActive()) {
            $this->status = SwarmStatus::Paused;
            $this->touch();
        }
    }

    public function complete(?string $reviewSummary = null): void
    {
        $now = new \DateTimeImmutable();
        $this->status = SwarmStatus::Completed;
        if (null !== $reviewSummary && '' !== trim($reviewSummary)) {
            $this->reviewSummary = trim($reviewSummary);
        }
        $this->updatedAt = $now;
        $this->finishedAt = $now;
    }

    public function fail(?string $reason = null): void
    {
        $now = new \DateTimeImmutable();
        $this->status = SwarmStatus::Failed;
        if (null !== $reason && '' !== trim($reason)) {
            $this->reviewSummary = trim($reason);
        }
        $this->updatedAt = $now;
        $this->finishedAt = $now;
    }

    public function cancel(?string $reason = null): void
    {
        $now = new \DateTimeImmutable();
        $this->status = SwarmStatus::Cancelled;
        if (null !== $reason && '' !== trim($reason)) {
            $this->reviewSummary = trim($reason);
        }
        $this->updatedAt = $now;
        $this->finishedAt = $now;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
