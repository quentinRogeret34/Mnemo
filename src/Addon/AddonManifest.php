<?php

namespace App\Addon;

final readonly class AddonManifest
{
    /**
     * @param list<string>       $presets
     * @param list<AddonNavItem> $navItems
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public AddonCategory $category,
        public AddonScope $scope,
        public string $icon,
        public string $color = 'accent',
        public bool $defaultEnabled = true,
        public array $presets = [],
        public array $navItems = [],
    ) {
    }
}
