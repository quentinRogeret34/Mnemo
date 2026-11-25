<?php

namespace App\Grooming\Dto;

/**
 * One hive agent turn in a Grooming Swarm run (Cartographe, Critique, or Groomer).
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
    ) {
    }

    /**
     * @return array{agent: string, role: string, summary: string, findings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'agent' => $this->agent,
            'role' => $this->role,
            'summary' => $this->summary,
            'findings' => $this->findings,
        ];
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

        return new self(
            agent: (string) ($data['agent'] ?? ''),
            role: (string) ($data['role'] ?? ''),
            summary: (string) ($data['summary'] ?? ''),
            findings: $findings,
        );
    }
}
