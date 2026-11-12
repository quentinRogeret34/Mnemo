<?php

namespace App\Enum;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Active => 'Actif',
            self::Disabled => 'Désactivé',
        };
    }
}
