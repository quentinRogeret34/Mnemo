<?php

namespace App\Alignment;

final readonly class AlignmentSummary
{
    public function __construct(
        public ?int $score = null,
        public int $total = 0,
        public int $aligned = 0,
        public int $toClarify = 0,
        public int $unlinked = 0,
        public int $drift = 0,
        public int $withSource = 0,
        public int $validated = 0,
        public int $withCode = 0,
        public int $blockers = 0,
        public int $refuted = 0,
    ) {
    }
}
