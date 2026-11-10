<?php

namespace App\Learn;

use App\Enum\MemoryType;

final readonly class Candidate
{
    /**
     * @param list<string>          $files
     * @param list<string>          $rules
     * @param array<string, string> $adr
     */
    public function __construct(
        public string $lesson,
        public string $evidence,
        public MemoryType $type,
        public int $durability,
        public int $reuse,
        public int $projectFit,
        public int $risk,
        public array $files,
        public array $rules,
        public array $adr,
    ) {
    }

    public function score(): float
    {
        return round(($this->durability + $this->reuse + $this->projectFit + $this->risk) / 4, 1);
    }
}
