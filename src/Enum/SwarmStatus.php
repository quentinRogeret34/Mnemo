<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum SwarmStatus: string implements TranslatableInterface
{
    case Planning = 'planning';
    case AwaitingApproval = 'awaiting_approval';
    case Running = 'running';
    case Reviewing = 'reviewing';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.swarm_status.'.$this->value, locale: $locale);
    }
}
