<?php

namespace App\Message;

final readonly class PlanSwarm
{
    public function __construct(
        public int $swarmId,
    ) {
    }
}
