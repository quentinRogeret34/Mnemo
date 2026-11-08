<?php

namespace App\Enum;

enum MemoryType: string
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
}
