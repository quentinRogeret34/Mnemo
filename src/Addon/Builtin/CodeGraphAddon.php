<?php

namespace App\Addon\Builtin;

use App\Addon\AddonCategory;
use App\Addon\AddonInterface;
use App\Addon\AddonManifest;
use App\Addon\AddonNavItem;
use App\Addon\AddonScope;

final readonly class CodeGraphAddon implements AddonInterface
{
    public const ID = 'code_graph';

    public function manifest(): AddonManifest
    {
        return new AddonManifest(
            id: self::ID,
            name: 'addon.code_graph.name',
            description: 'addon.code_graph.description',
            category: AddonCategory::Factory,
            scope: AddonScope::Project,
            icon: 'lucide:code',
            color: 'series',
            defaultEnabled: true,
            presets: ['factory'],
            navItems: [
                new AddonNavItem(
                    route: 'project_code',
                    label: 'nav.code',
                    icon: 'lucide:code',
                    group: 'knowledge',
                    projectScoped: true,
                    activeRoutes: ['project_code', 'file_show'],
                ),
            ],
        );
    }
}
