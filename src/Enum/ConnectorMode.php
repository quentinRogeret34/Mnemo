<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ConnectorMode: string implements TranslatableInterface
{
    case Resources = 'resources';
    case ToolDocument = 'tool_document';
    case ToolMemories = 'tool_memories';
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Notion = 'notion';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.connector_mode.'.$this->value, locale: $locale);
    }

    /**
     * Forges are read through their REST API, not through an MCP server.
     */
    public function isForge(): bool
    {
        return \in_array($this, [self::GitHub, self::GitLab], true);
    }

    /**
     * Read through a REST API rather than through an MCP server.
     */
    public function isApi(): bool
    {
        return $this->isForge() || self::Notion === $this;
    }
}
