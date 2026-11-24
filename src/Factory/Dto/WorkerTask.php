<?php

namespace App\Factory\Dto;

final readonly class WorkerTask
{
    /**
     * @param list<string> $filesHint
     */
    public function __construct(
        public string $swarmPublicId,
        public string $slug,
        public string $title,
        public string $role,
        public string $instructions,
        public string $projectContext,
        public ?string $worktreePath = null,
        public ?string $branch = null,
        public array $filesHint = [],
    ) {
    }
}
