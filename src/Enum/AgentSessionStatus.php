<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AgentSessionStatus: string implements TranslatableInterface
{
    case Queued = 'queued';
    case Running = 'running';
    case WaitingHuman = 'waiting_human';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            self::Queued, self::Running, self::WaitingHuman => false,
        };
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.agent_session_status.'.$this->value, locale: $locale);
    }
}
