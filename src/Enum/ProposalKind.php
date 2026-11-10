<?php

namespace App\Enum;

enum ProposalKind: string
{
    case Learning = 'learning';
    case Duplicate = 'duplicate';
    case Contradiction = 'contradiction';
    case Refuted = 'refuted';

    public function label(): string
    {
        return match ($this) {
            self::Learning => 'Apprentissage',
            self::Duplicate => 'Doublon',
            self::Contradiction => 'Contradiction',
            self::Refuted => 'Mémoire réfutée',
        };
    }
}
