<?php

namespace App\Addon;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AddonScope: string implements TranslatableInterface
{
    case Workspace = 'workspace';
    case Project = 'project';
    case Both = 'both';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('addon.scope.'.$this->value, locale: $locale);
    }
}
