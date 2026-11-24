<?php

namespace App\Message;

final readonly class GardenProject
{
    public function __construct(
        public int $projectId,
    ) {
    }
}
