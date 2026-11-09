<?php

namespace App\Ingest;

final readonly class GraphifyImportResult
{
    public function __construct(
        public int $nodesCreated,
        public int $nodesUpdated,
        public int $nodesDeleted,
        public int $edgesCreated,
        public int $edgesDeleted,
        public int $manualEdgesMoved,
        public int $manualEdgesLost,
        public ?string $commit,
    ) {
    }
}
