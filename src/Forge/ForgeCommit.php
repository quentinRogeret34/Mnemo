<?php

namespace App\Forge;

final readonly class ForgeCommit
{
    /**
     * @param list<string> $files paths changed by the commit, relative to the repository root
     */
    public function __construct(
        public string $sha,
        public string $message,
        public string $author,
        public \DateTimeImmutable $date,
        public string $url,
        public array $files,
    ) {
    }
}
