<?php

namespace App\Forge;

/**
 * An issue, or a change request (pull request on GitHub, merge request on GitLab).
 */
final readonly class ForgeTicket
{
    /**
     * @param list<string> $commits shas of a change request
     */
    public function __construct(
        public bool $changeRequest,
        public int $number,
        public string $title,
        public string $body,
        public string $state,
        public string $author,
        public \DateTimeImmutable $updatedAt,
        public string $url,
        public array $commits = [],
    ) {
    }
}
