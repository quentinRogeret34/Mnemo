<?php

namespace App\Factory\Dto;

final readonly class FactoryStats
{
    public function __construct(
        public int $total,
        public int $active,
        public int $succeeded,
        public int $failed,
        public int $last24h,
    ) {
    }
}
