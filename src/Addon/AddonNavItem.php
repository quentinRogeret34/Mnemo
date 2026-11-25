<?php

namespace App\Addon;

final readonly class AddonNavItem
{
    /**
     * @param list<string> $activeRoutes
     */
    public function __construct(
        public string $route,
        public string $label,
        public string $icon,
        public string $group = 'studio_agents',
        public bool $projectScoped = true,
        public array $activeRoutes = [],
    ) {
    }

    public function isActive(?string $currentRoute): bool
    {
        if (null === $currentRoute) {
            return false;
        }

        return $currentRoute === $this->route || \in_array($currentRoute, $this->activeRoutes, true);
    }
}
