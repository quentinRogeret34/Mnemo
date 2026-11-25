<?php

namespace App\Grooming\Dto;

use App\Entity\GroomingRun;
use App\Entity\Proposal;

/**
 * Result of executing the 3-agent Grooming Swarm on a topic.
 */
final readonly class GroomingReport
{
    /**
     * @param list<GroomingStep>     $steps
     * @param list<GroomingQuestion> $questions
     * @param list<Proposal>         $createdProposals
     */
    public function __construct(
        public GroomingRun $run,
        public int $readinessScore,
        public string $summary,
        public array $steps,
        public array $questions,
        public array $createdProposals,
        public int $skippedDuplicates = 0,
    ) {
    }
}
