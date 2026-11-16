<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AgentAssetKind: string implements TranslatableInterface
{
    case Skill = 'skill';
    case Command = 'command';
    case Agent = 'agent';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.agent_asset_kind.'.$this->value, locale: $locale);
    }

    /**
     * Where Claude Code looks for it, from the root of the repository.
     */
    public function path(string $name): string
    {
        return match ($this) {
            self::Skill => '.claude/skills/'.$name.'/SKILL.md',
            self::Command => '.claude/commands/'.$name.'.md',
            self::Agent => '.claude/agents/'.$name.'.md',
        };
    }
}
