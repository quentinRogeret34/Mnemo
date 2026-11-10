<?php

namespace App\Learn;

enum SourceKind: string
{
    case Conversation = 'conversation';
    case File = 'file';
    case Diff = 'diff';
    case Review = 'review';

    public function describe(): string
    {
        return match ($this) {
            self::Conversation => 'une conversation entre un développeur et un agent IA',
            self::File => 'un fichier du projet',
            self::Diff => 'un diff de code (commits ou branche)',
            self::Review => 'une revue de code (merge request, pull request, commentaires)',
        };
    }
}
