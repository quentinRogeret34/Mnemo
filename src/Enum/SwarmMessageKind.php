<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum SwarmMessageKind: string implements TranslatableInterface
{
    case Brief = 'brief';
    case Plan = 'plan';
    case Progress = 'progress';
    case Handoff = 'handoff';
    case Review = 'review';
    case Human = 'human';
    case System = 'system';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.swarm_message_kind.'.$this->value, locale: $locale);
    }
}
