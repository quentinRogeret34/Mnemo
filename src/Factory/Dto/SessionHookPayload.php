<?php

namespace App\Factory\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * JSON payload accepted by POST /api/factory/sessions/hook (Claude Code hooks, shell scripts, CI wrappers).
 */
final readonly class SessionHookPayload
{
    /**
     * @param list<string>         $files
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['start', 'event', 'end'])]
        public string $action,
        #[Assert\Length(max: 100)]
        public ?string $project = null,
        #[Assert\Length(max: 40)]
        public ?string $sessionId = null,
        #[Assert\Length(max: 255)]
        public ?string $title = null,
        public ?string $goal = null,
        #[Assert\Length(max: 60)]
        public ?string $role = null,
        #[Assert\Length(max: 30)]
        public ?string $runner = null,
        #[Assert\Length(max: 100)]
        public ?string $client = null,
        #[Assert\Length(max: 200)]
        public ?string $branch = null,
        #[Assert\Length(max: 50)]
        public ?string $ruleCode = null,
        #[Assert\Length(max: 40)]
        public ?string $parentSessionId = null,
        #[Assert\Length(max: 30)]
        public ?string $kind = null,
        public ?string $message = null,
        #[Assert\Choice(choices: ['succeeded', 'failed', 'cancelled', null])]
        public ?string $status = null,
        public ?string $summary = null,
        public array $files = [],
        public array $payload = [],
    ) {
    }
}
