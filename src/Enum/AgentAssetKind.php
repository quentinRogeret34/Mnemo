<?php

namespace App\Enum;

enum AgentAssetKind: string
{
    case Skill = 'skill';
    case Command = 'command';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Skill => 'Skill',
            self::Command => 'Commande',
            self::Agent => 'Sous-agent',
        };
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
