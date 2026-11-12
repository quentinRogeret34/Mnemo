<?php

namespace App\Enum;

enum ConnectorSchedule: string
{
    case Manual = 'manual';
    case Hourly = 'hourly';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuelle',
            self::Hourly => 'Toutes les heures',
            self::Daily => 'Tous les jours',
        };
    }

    /**
     * The scheduler ticks every hour: a few minutes of slack keep a run from being skipped to the next tick.
     */
    public function isDue(?\DateTimeImmutable $lastRunAt, \DateTimeImmutable $now): bool
    {
        return match ($this) {
            self::Manual => false,
            self::Hourly => null === $lastRunAt || $lastRunAt <= $now->modify('-55 minutes'),
            self::Daily => null === $lastRunAt || $lastRunAt <= $now->modify('-23 hours 55 minutes'),
        };
    }
}
