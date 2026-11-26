<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum SwarmOrderStatus: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Pending, self::Claimed, self::Running => false,
        };
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.swarm_order_status.'.$this->value, locale: $locale);
    }
}
