<?php

namespace App\Alignment;

final readonly class AlignmentResult
{
    /**
     * @param list<AlignmentRow> $rows
     */
    public function __construct(
        public AlignmentSummary $summary,
        public array $rows,
    ) {
    }
}
