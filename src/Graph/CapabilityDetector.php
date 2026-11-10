<?php

namespace App\Graph;

use App\Entity\Project;
use App\Enum\NodeType;
use Doctrine\DBAL\Connection;

final class CapabilityDetector
{
    private const EVIDENCE = 3;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{capability: Capability, evidence: list<string>}> in the enum order, each with the first matching paths
     */
    public function detect(Project $project): array
    {
        $paths = $this->connection->fetchFirstColumn(
            'SELECT path FROM node WHERE project_id = :project AND type = :file AND path IS NOT NULL ORDER BY path',
            ['project' => $project->getId(), 'file' => NodeType::CodeFile->value],
        );

        $detected = [];
        foreach (Capability::cases() as $capability) {
            $evidence = \array_slice(preg_grep($capability->pattern(), $paths) ?: [], 0, self::EVIDENCE);
            if ([] !== $evidence) {
                $detected[] = ['capability' => $capability, 'evidence' => array_values($evidence)];
            }
        }

        return $detected;
    }
}
