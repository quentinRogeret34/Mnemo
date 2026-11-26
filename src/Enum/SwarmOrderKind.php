<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum SwarmOrderKind: string implements TranslatableInterface
{
    case Research = 'research';
    case Edit = 'edit';
    case Review = 'review';
    case Custom = 'custom';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.swarm_order_kind.'.$this->value, locale: $locale);
    }
}
