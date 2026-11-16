<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum MemoryType: string implements TranslatableInterface
{
    case Decision = 'decision';
    case Convention = 'convention';
    case Learning = 'task_learning';
    case Preference = 'preference';
    case Fact = 'fact';

    public function label(): string
    {
        return match ($this) {
            self::Decision => 'Décision',
            self::Convention => 'Convention',
            self::Learning => 'Apprentissage',
            self::Preference => 'Préférence',
            self::Fact => 'Fait',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.memory_type.'.$this->value, locale: $locale);
    }
}
