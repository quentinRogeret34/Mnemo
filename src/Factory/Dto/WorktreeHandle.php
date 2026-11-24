<?php

namespace App\Factory\Dto;

final readonly class WorktreeHandle
{
    public function __construct(
        public string $path,
        public string $branch,
    ) {
    }
}
