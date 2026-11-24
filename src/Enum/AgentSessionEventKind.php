<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AgentSessionEventKind: string implements TranslatableInterface
{
    case Started = 'started';
    case Step = 'step';
    case ToolCall = 'tool_call';
    case FileChanged = 'file_changed';
    case Log = 'log';
    case Decision = 'decision';
    case Handoff = 'handoff';
    case Error = 'error';
    case Completed = 'completed';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.agent_session_event_kind.'.$this->value, locale: $locale);
    }
}
