<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum MemoryVisibility: string implements TranslatableInterface
{
    case Project = 'project';
    case Group = 'group';
    case Personal = 'private';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.memory_visibility.'.$this->value, locale: $locale);
    }
}
