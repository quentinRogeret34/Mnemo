<?php

namespace App\Addon\Builtin;

use App\Addon\AddonCategory;
use App\Addon\AddonInterface;
use App\Addon\AddonManifest;
use App\Addon\AddonNavItem;
use App\Addon\AddonScope;

final readonly class StudioAddon implements AddonInterface
{
    public const ID = 'studio';

    public function manifest(): AddonManifest
    {
        return new AddonManifest(
            id: self::ID,
            name: 'addon.studio.name',
            description: 'addon.studio.description',
            category: AddonCategory::Factory,
            scope: AddonScope::Project,
            icon: 'lucide:file-code',
            color: 'accent',
            defaultEnabled: true,
            presets: ['factory'],
            navItems: [
                new AddonNavItem(
                    route: 'project_studio',
                    label: 'nav.studio',
                    icon: 'lucide:file-code',
                    group: 'studio_agents',
                    projectScoped: true,
                    activeRoutes: ['project_studio'],
                ),
            ],
        );
    }
}
