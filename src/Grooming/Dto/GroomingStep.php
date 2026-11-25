<?php

namespace App\Grooming\Dto;

/**
 * One agent intervention in a multi-round Grooming Swarm debate or final synthesis.
 */
final readonly class GroomingStep
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public string $agent,
        public string $role,
        public string $summary,
        public array $findings,
        public int $round = 1,
        public ?string $stance = null,
    ) {
    }

    /**
     * @return array{agent: string, role: string, summary: string, findings: list<string>, round: int, stance?: string}
     */
    public function toArray(): array
    {
        $data = [
            'agent' => $this->agent,
            'role' => $this->role,
            'summary' => $this->summary,
            'findings' => $this->findings,
            'round' => $this->round,
        ];
        if (null !== $this->stance && '' !== $this->stance) {
            $data['stance'] = $this->stance;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawFindings = \is_array($data['findings'] ?? null) ? $data['findings'] : [];
        $findings = [];
        foreach ($rawFindings as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $findings[] = trim($item);
            }
        }
        $stance = isset($data['stance']) && \is_string($data['stance']) && '' !== trim($data['stance'])
            ? trim($data['stance'])
            : null;

        return new self(
            agent: (string) ($data['agent'] ?? ''),
            role: (string) ($data['role'] ?? ''),
            summary: (string) ($data['summary'] ?? ''),
            findings: $findings,
            round: max(1, (int) ($data['round'] ?? 1)),
            stance: $stance,
        );
    }
}
