<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One 3-agent grooming swarm execution (Cartographe -> Critique -> Groomer) on a project topic.
 */
#[ORM\Entity]
#[ORM\Table(name: 'grooming_run')]
#[ORM\Index(name: 'grooming_run_project_created_idx', columns: ['project_id', 'created_at'])]
class GroomingRun
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $context = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_COMPLETED;

    #[ORM\Column]
    private int $readinessScore = 0;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary = '';

    /**
     * @var list<array{agent: string, role: string, summary: string, findings: list<string>}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $steps = [];

    /**
     * @var list<array{question: string, severity: string, rationale: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $questions = [];

    /**
     * @var list<int>
     */
    #[ORM\Column(name: 'proposals_created', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $createdProposalIds = [];

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: 255)]
        private string $topic,
        ?string $context = null,
        ?string $createdBy = null,
    ) {
        $this->topic = mb_substr(trim($topic), 0, 255);
        $trimmedContext = null === $context ? null : trim($context);
        $this->context = '' === $trimmedContext ? null : $trimmedContext;
        $trimmedBy = null === $createdBy ? null : mb_substr(trim($createdBy), 0, 150);
        $this->createdBy = '' === $trimmedBy ? null : $trimmedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReadinessScore(): int
    {
        return $this->readinessScore;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    /**
     * @return list<array{agent: string, role: string, summary: string, findings: list<string>}>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<array{question: string, severity: string, rationale: string}>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /**
     * @return list<int>
     */
    public function getCreatedProposalIds(): array
    {
        return $this->createdProposalIds;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param list<array{agent: string, role: string, summary: string, findings: list<string>}> $steps
     * @param list<array{question: string, severity: string, rationale: string}>                $questions
     * @param list<int>                                                                         $createdProposalIds
     */
    public function complete(int $readinessScore, string $summary, array $steps, array $questions, array $createdProposalIds): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->readinessScore = max(0, min(100, $readinessScore));
        $this->summary = trim($summary);
        $this->steps = $steps;
        $this->questions = $questions;
        $this->createdProposalIds = array_values(array_unique(array_map('intval', $createdProposalIds)));
    }

    /**
     * @param list<array{agent: string, role: string, summary: string, findings: list<string>}> $steps
     */
    public function fail(string $reason, array $steps = []): void
    {
        $this->status = self::STATUS_FAILED;
        $this->readinessScore = 0;
        $this->summary = trim($reason);
        $this->steps = $steps;
    }

    public function blockingQuestionsCount(): int
    {
        return \count(array_filter(
            $this->questions,
            static fn (array $question): bool => 'blocking' === $question['severity'],
        ));
    }
}
