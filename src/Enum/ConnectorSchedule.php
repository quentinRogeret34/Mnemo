<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ConnectorSchedule: string implements TranslatableInterface
{
    case Manual = 'manual';
    case Hourly = 'hourly';
    case Daily = 'daily';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.connector_schedule.'.$this->value, locale: $locale);
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
