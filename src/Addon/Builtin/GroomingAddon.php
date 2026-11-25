<?php

namespace App\Addon\Builtin;

use App\Addon\AddonCategory;
use App\Addon\AddonInterface;
use App\Addon\AddonManifest;
use App\Addon\AddonNavItem;
use App\Addon\AddonScope;
use App\Grooming\GroomingAddonMetadata;

final readonly class GroomingAddon implements AddonInterface
{
    public const ID = GroomingAddonMetadata::ID;

    public function manifest(): AddonManifest
    {
        return new AddonManifest(
            id: self::ID,
            name: GroomingAddonMetadata::NAME_KEY,
            description: GroomingAddonMetadata::DESCRIPTION_KEY,
            category: AddonCategory::Knowledge,
            scope: AddonScope::Project,
            icon: GroomingAddonMetadata::ICON,
            color: 'violet',
            defaultEnabled: true,
            presets: ['business', 'factory'],
            navItems: [
                new AddonNavItem(
                    route: GroomingAddonMetadata::NAV_ROUTE,
                    label: GroomingAddonMetadata::NAV_LABEL_KEY,
                    icon: GroomingAddonMetadata::ICON,
                    group: 'studio_agents',
                    projectScoped: true,
                    activeRoutes: ['project_grooming', 'project_grooming_show'],
                ),
            ],
        );
    }
}
