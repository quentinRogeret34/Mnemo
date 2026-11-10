<?php

namespace App\Ingest;

final class RepoContextImportResult
{
    public int $memoriesCreated = 0;
    public int $memoriesUnchanged = 0;
    public int $decisionsCreated = 0;
    public int $decisionsUpdated = 0;
    public int $decisionsUnchanged = 0;
    public int $documentsCreated = 0;
    public int $documentsUpdated = 0;
    public int $documentsUnchanged = 0;

    /** @var list<string> */
    public array $skipped = [];

    /** @var list<string> files imported before and gone from the repository, kept in the graph */
    public array $missing = [];
}
