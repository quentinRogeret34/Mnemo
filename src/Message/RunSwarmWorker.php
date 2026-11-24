<?php

namespace App\Message;

final readonly class RunSwarmWorker
{
    public function __construct(
        public int $swarmId,
        public int $sessionId,
        public int $taskIndex,
    ) {
    }
}
