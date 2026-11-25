<?php

namespace App\Addon\Builtin;

use App\Addon\AddonCategory;
use App\Addon\AddonInterface;
use App\Addon\AddonManifest;
use App\Addon\AddonNavItem;
use App\Addon\AddonScope;

final readonly class GardenAddon implements AddonInterface
{
    public const ID = 'garden';

    public function manifest(): AddonManifest
    {
        return new AddonManifest(
            id: self::ID,
            name: 'addon.garden.name',
            description: 'addon.garden.description',
            category: AddonCategory::Knowledge,
            scope: AddonScope::Project,
            icon: 'lucide:sprout',
            color: 'success',
            defaultEnabled: true,
            presets: ['business', 'factory'],
            navItems: [
                new AddonNavItem(
                    route: 'garden_show',
                    label: 'nav.garden',
                    icon: 'lucide:sprout',
                    group: 'studio_agents',
                    projectScoped: true,
                    activeRoutes: ['garden_show'],
                ),
            ],
        );
    }
}
