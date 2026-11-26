<?php

namespace App\Entity;

use App\Enum\SwarmOrderKind;
use App\Enum\SwarmOrderStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One executable work order dispatched inside an interactive Swarm Hub (targeted, open-claim, or broadcast).
 */
#[ORM\Entity]
#[ORM\Table(name: 'swarm_order')]
#[ORM\UniqueConstraint(name: 'swarm_order_public_id_uidx', columns: ['public_id'])]
#[ORM\Index(name: 'swarm_order_swarm_status_created_idx', columns: ['swarm_id', 'status', 'created_at'])]
#[ORM\Index(name: 'swarm_order_target_node_idx', columns: ['target_node_id'])]
#[ORM\Index(name: 'swarm_order_claimed_by_node_idx', columns: ['claimed_by_node_id'])]
class SwarmOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $publicId;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $filesHint = [];

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $ruleCode = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?SwarmNode $claimedByNode = null;

    #[ORM\Column(length: 20, enumType: SwarmOrderStatus::class, options: ['default' => 'pending'])]
    private SwarmOrderStatus $status = SwarmOrderStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultSummary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultOutput = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $filesTouched = [];

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $branch = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param list<string> $filesHint
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SwarmRun $swarm,
        #[ORM\Column(length: 20, enumType: SwarmOrderKind::class)]
        private SwarmOrderKind $kind,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $instructions,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?SwarmNode $targetNode = null,
        array $filesHint = [],
        ?string $ruleCode = null,
        ?string $createdBy = null,
        ?string $publicId = null,
    ) {
        $this->publicId = $publicId ?? 'ord_'.bin2hex(random_bytes(6));
        $this->title = mb_substr(trim($title), 0, 255);
        $this->instructions = trim($instructions);
        $this->filesHint = self::normalizeFileList($filesHint);
        $this->setRuleCode($ruleCode);
        $this->setCreatedBy($createdBy);
        $this->createdAt = new \DateTimeImmutable();
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

    public function getKind(): SwarmOrderKind
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return list<string>
     */
    public function getFilesHint(): array
    {
        return $this->filesHint;
    }

    public function getRuleCode(): ?string
    {
        return $this->ruleCode;
    }

    public function setRuleCode(?string $ruleCode): void
    {
        $trimmed = null === $ruleCode ? null : mb_substr(trim($ruleCode), 0, 50);
        $this->ruleCode = '' === $trimmed ? null : $trimmed;
    }

    public function getTargetNode(): ?SwarmNode
    {
        return $this->targetNode;
    }

    public function getClaimedByNode(): ?SwarmNode
    {
        return $this->claimedByNode;
    }

    public function getStatus(): SwarmOrderStatus
    {
        return $this->status;
    }

    public function getResultSummary(): ?string
    {
        return $this->resultSummary;
    }

    public function getResultOutput(): ?string
    {
        return $this->resultOutput;
    }

    /**
     * @return list<string>
     */
    public function getFilesTouched(): array
    {
        return $this->filesTouched;
    }

    public function getBranch(): ?string
    {
        return $this->branch;
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

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function claim(SwarmNode $node): void
    {
        $this->claimedByNode = $node;
        $this->status = SwarmOrderStatus::Claimed;
        $this->claimedAt = new \DateTimeImmutable();
    }

    public function markRunning(): void
    {
        if ($this->status->isTerminal()) {
            return;
        }

        $this->status = SwarmOrderStatus::Running;
        $this->claimedAt ??= new \DateTimeImmutable();
    }

    /**
     * @param list<string> $files
     */
    public function mergeFilesTouched(array $files): void
    {
        $clean = self::normalizeFileList($files);
        if ([] === $clean) {
            return;
        }

        $this->filesTouched = array_values(array_unique([...$this->filesTouched, ...$clean]));
    }

    /**
     * @param list<string> $filesTouched
     */
    public function complete(
        string $summary,
        ?string $output = null,
        array $filesTouched = [],
        ?string $branch = null,
    ): void {
        $this->status = SwarmOrderStatus::Completed;
        $this->resultSummary = trim($summary);
        $trimmedOutput = null === $output ? null : trim($output);
        $this->resultOutput = '' === $trimmedOutput ? null : $trimmedOutput;
        $this->mergeFilesTouched($filesTouched);
        $trimmedBranch = null === $branch ? null : mb_substr(trim($branch), 0, 200);
        if (null !== $trimmedBranch && '' !== $trimmedBranch) {
            $this->branch = $trimmedBranch;
        }
        $this->finishedAt = new \DateTimeImmutable();
    }

    /**
     * @param list<string> $filesTouched
     */
    public function fail(
        string $summary,
        ?string $output = null,
        array $filesTouched = [],
    ): void {
        $this->status = SwarmOrderStatus::Failed;
        $this->resultSummary = trim($summary);
        $trimmedOutput = null === $output ? null : trim($output);
        $this->resultOutput = '' === $trimmedOutput ? null : $trimmedOutput;
        $this->mergeFilesTouched($filesTouched);
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function cancel(): void
    {
        if ($this->status->isTerminal()) {
            return;
        }

        $this->status = SwarmOrderStatus::Cancelled;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private static function normalizeFileList(array $files): array
    {
        $clean = [];
        foreach ($files as $file) {
            $trimmed = trim($file);
            if ('' !== $trimmed) {
                $clean[] = $trimmed;
            }
        }

        return array_values(array_unique($clean));
    }
}
