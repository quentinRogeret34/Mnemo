<?php

namespace App\Message;

final readonly class RunGroomingSwarm
{
    public function __construct(
        public int $runId,
    ) {
    }
}
