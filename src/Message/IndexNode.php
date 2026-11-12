<?php

namespace App\Message;

final readonly class IndexNode
{
    public function __construct(
        public int $nodeId,
    ) {
    }
}
