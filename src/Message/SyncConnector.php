<?php

namespace App\Message;

final readonly class SyncConnector
{
    public function __construct(
        public int $connectorId,
    ) {
    }
}
