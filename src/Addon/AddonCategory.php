<?php

namespace App\Addon;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AddonCategory: string implements TranslatableInterface
{
    case Knowledge = 'knowledge';
    case Factory = 'factory';
    case Integration = 'integration';
    case Runner = 'runner';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('addon.category.'.$this->value, locale: $locale);
    }
}
