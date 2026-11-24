<?php

namespace App\Alignment;

final readonly class AlignmentRow
{
    /**
     * @param list<string> $flags
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $title,
        public string $status,
        public string $projectSlug,
        public string $projectName,
        public int $sourcesCount,
        public int $codeCount,
        public int $verifiedCodeCount,
        public bool $shadowChecked,
        public int $shadowOpen,
        public int $shadowBlockers,
        public int $refutedMemories,
        public int $decisionsCount,
        public ?string $lastTicketRef,
        public ?string $lastTicketLabel,
        public ?string $lastTicketUrl,
        public int $readiness,
        public string $state,
        public array $flags,
    ) {
    }
}
