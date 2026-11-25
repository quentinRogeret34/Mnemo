<?php

namespace App\Grooming;

/**
 * Self-contained manifest metadata for the "Ruche de grooming" Marketplace addon.
 *
 * Once App\Addon\AddonInterface lands, a thin App\Grooming\GroomingAddon adapter can expose
 * these constants directly as an AddonManifest + AddonNavItem (route: project_grooming).
 */
final class GroomingAddonMetadata
{
    public const ID = 'grooming-swarm';
    public const NAME_KEY = 'grooming.addon.name';
    public const DESCRIPTION_KEY = 'grooming.addon.description';
    public const CATEGORY = 'knowledge';
    public const SCOPE = 'project';
    public const ICON = 'lucide:wand-sparkles';
    public const NAV_ROUTE = 'project_grooming';
    public const NAV_LABEL_KEY = 'grooming.nav';
    public const VERSION = '1.0.0';
}
