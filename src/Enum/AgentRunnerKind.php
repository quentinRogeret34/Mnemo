<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AgentRunnerKind: string implements TranslatableInterface
{
    case ExternalMcp = 'external_mcp';
    case ClaudeCli = 'claude_cli';
    case CodexCli = 'codex_cli';
    case GeminiCli = 'gemini_cli';
    case InternalLlm = 'internal_llm';
    case ExternalHub = 'external_hub';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.agent_runner_kind.'.$this->value, locale: $locale);
    }
}
