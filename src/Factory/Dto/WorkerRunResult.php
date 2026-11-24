<?php

namespace App\Factory\Dto;

final readonly class WorkerRunResult
{
    /**
     * @param list<string> $filesTouched
     */
    public function __construct(
        public bool $succeeded,
        public string $summary,
        public string $output,
        public array $filesTouched = [],
        public int $exitCode = 0,
    ) {
    }
}
