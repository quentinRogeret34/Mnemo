<?php

namespace App\Enum;

enum ConnectorTransport: string
{
    case Http = 'http';
    case Stdio = 'stdio';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP (serveur distant)',
            self::Stdio => 'STDIO (commande locale)',
        };
    }
}
