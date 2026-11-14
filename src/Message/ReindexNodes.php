<?php

namespace App\Message;

/**
 * Embeds again what an embedding model change left behind: the nodes of a project, or all of them.
 */
final readonly class ReindexNodes
{
    public function __construct(
        public ?int $projectId = null,
    ) {
    }
}
