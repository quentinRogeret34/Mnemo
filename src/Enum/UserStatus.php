<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum UserStatus: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.user_status.'.$this->value, locale: $locale);
    }
}
