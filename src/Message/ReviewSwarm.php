<?php

namespace App\Message;

final readonly class ReviewSwarm
{
    public function __construct(
        public int $swarmId,
    ) {
    }
}
