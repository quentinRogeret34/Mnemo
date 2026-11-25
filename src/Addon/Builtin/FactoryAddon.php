<?php

namespace App\Addon\Builtin;

use App\Addon\AddonCategory;
use App\Addon\AddonInterface;
use App\Addon\AddonManifest;
use App\Addon\AddonNavItem;
use App\Addon\AddonScope;

final readonly class FactoryAddon implements AddonInterface
{
    public const ID = 'factory';

    public function manifest(): AddonManifest
    {
        return new AddonManifest(
            id: self::ID,
            name: 'addon.factory.name',
            description: 'addon.factory.description',
            category: AddonCategory::Factory,
            scope: AddonScope::Both,
            icon: 'lucide:kanban',
            color: 'violet',
            defaultEnabled: true,
            presets: ['factory'],
            navItems: [
                new AddonNavItem(
                    route: 'project_factory',
                    label: 'nav.factory',
                    icon: 'lucide:kanban',
                    group: 'studio_agents',
                    projectScoped: true,
                    activeRoutes: ['project_factory', 'factory_session_show', 'factory_swarm_show'],
                ),
                new AddonNavItem(
                    route: 'factory_index',
                    label: 'nav.factory',
                    icon: 'lucide:kanban',
                    group: 'studio_agents',
                    projectScoped: false,
                    activeRoutes: ['factory_index', 'factory_session_show', 'factory_swarm_show'],
                ),
            ],
        );
    }
}
