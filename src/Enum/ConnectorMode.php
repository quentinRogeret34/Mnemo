<?php

namespace App\Enum;

enum ConnectorMode: string
{
    case Resources = 'resources';
    case ToolDocument = 'tool_document';
    case ToolMemories = 'tool_memories';

    public function label(): string
    {
        return match ($this) {
            self::Resources => 'Ressources → documents',
            self::ToolDocument => 'Outil → un document',
            self::ToolMemories => 'Outil → mémoires',
        };
    }
}
