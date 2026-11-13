<?php

namespace App\Message;

use App\Entity\ConnectorRun;

final readonly class SyncConnector
{
    public function __construct(
        public int $connectorId,
        public string $trigger = ConnectorRun::SCHEDULE,
    ) {
    }
}
