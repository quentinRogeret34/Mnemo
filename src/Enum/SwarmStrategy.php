<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum SwarmStrategy: string implements TranslatableInterface
{
    case Pipeline = 'pipeline';
    case Parallel = 'parallel';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.swarm_strategy.'.$this->value, locale: $locale);
    }
}
