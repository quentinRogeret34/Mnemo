<?php

namespace App\Factory\Dto;

final readonly class WorktreeDiff
{
    /**
     * @param list<string> $filesChanged
     */
    public function __construct(
        public array $filesChanged,
        public string $stat,
        public string $diff,
    ) {
    }
}
