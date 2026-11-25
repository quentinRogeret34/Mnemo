<?php

namespace App\Grooming\Dto;

/**
 * One prioritized open business question surfaced by the Grooming Swarm.
 */
final readonly class GroomingQuestion
{
    public const SEVERITY_BLOCKING = 'blocking';
    public const SEVERITY_IMPORTANT = 'important';
    public const SEVERITY_NICE_TO_HAVE = 'nice_to_have';

    /** @var list<string> */
    public const SEVERITIES = [
        self::SEVERITY_BLOCKING,
        self::SEVERITY_IMPORTANT,
        self::SEVERITY_NICE_TO_HAVE,
    ];

    public function __construct(
        public string $question,
        public string $severity,
        public string $rationale,
    ) {
    }

    public static function normalizeSeverity(string $value): string
    {
        $normalized = strtolower(trim($value));

        return \in_array($normalized, self::SEVERITIES, true) ? $normalized : self::SEVERITY_IMPORTANT;
    }

    public function priorityRank(): int
    {
        $index = array_search($this->severity, self::SEVERITIES, true);

        return false === $index ? 99 : $index;
    }

    /**
     * @return array{question: string, severity: string, rationale: string}
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'severity' => $this->severity,
            'rationale' => $this->rationale,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            question: trim((string) ($data['question'] ?? '')),
            severity: self::normalizeSeverity((string) ($data['severity'] ?? self::SEVERITY_IMPORTANT)),
            rationale: trim((string) ($data['rationale'] ?? '')),
        );
    }
}
