<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum DashboardRole: string implements TranslatableInterface
{
    case All = 'all';
    case Business = 'business';
    case Dev = 'dev';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.dashboard_role.'.$this->value, locale: $locale);
    }
}
