<?php

namespace App\Enum;

enum ConnectorMode: string
{
    case Resources = 'resources';
    case ToolDocument = 'tool_document';
    case ToolMemories = 'tool_memories';
    case GitHub = 'github';
    case GitLab = 'gitlab';

    public function label(): string
    {
        return match ($this) {
            self::Resources => 'Ressources → documents',
            self::ToolDocument => 'Outil → un document',
            self::ToolMemories => 'Outil → mémoires',
            self::GitHub => 'API GitHub → commits, PR, issues',
            self::GitLab => 'API GitLab → commits, MR, issues',
        };
    }

    /**
     * Forges are read through their REST API, not through an MCP server.
     */
    public function isForge(): bool
    {
        return \in_array($this, [self::GitHub, self::GitLab], true);
    }
}
