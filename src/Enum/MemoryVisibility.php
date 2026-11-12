<?php

namespace App\Enum;

enum MemoryVisibility: string
{
    case Project = 'project';
    case Group = 'group';
    case Personal = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Projet',
            self::Group => 'Groupe',
            self::Personal => 'Privée',
        };
    }
}
