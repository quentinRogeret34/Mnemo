<?php

namespace App\Message;

final readonly class ReviewPullRequest
{
    public function __construct(
        public int $connectorId,
        public int $number,
    ) {
    }
}
