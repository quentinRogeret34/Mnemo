<?php

namespace App\Twig\Components;

use App\Addon\AddonManager;
use App\Addon\AddonNavItem;
use App\Addon\Builtin\FactoryAddon;
use App\Entity\Project;
use App\Factory\FactoryGuard;
use App\Graph\KnowledgeReader;
use App\Project\ProjectResolver;
use App\Security\ProjectScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Sidebar
{
    private const BUILTIN_NAV_ROUTES = [
        'project_factory',
        'factory_index',
        'project_studio',
        'project_code',
        'garden_show',
    ];

    public ?Project $project = null;

    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly ProjectScope $projectScope,
        private readonly ProjectResolver $projectResolver,
        private readonly FactoryGuard $factoryGuard,
        private readonly AddonManager $addonManager,
    ) {
    }

    public function isFactoryAvailable(): bool
    {
        return $this->factoryGuard->canOperate()
            && $this->addonManager->isEnabled(FactoryAddon::ID, $this->project);
    }

    public function isAddonEnabled(string $addonId): bool
    {
        return $this->addonManager->isEnabled($addonId, $this->project);
    }

    /**
     * Navigation items contributed by enabled addons beyond the built-in sidebar entries.
     *
     * @return list<AddonNavItem>
     */
    public function getExtraNavItems(string $group): array
    {
        return array_values(array_filter(
            $this->addonManager->navItemsFor($group, $this->project),
            static fn (AddonNavItem $item): bool => !\in_array($item->route, self::BUILTIN_NAV_ROUTES, true),
        ));
    }

    public function mayCreate(): bool
    {
        return $this->projectResolver->mayCreate();
    }

    /**
     * @return list<Project>
     */
    public function getProjects(): array
    {
        return $this->projectScope->projects();
    }

    /**
     * A caller limited to some projects has no view across all of them: the workspace entries are hidden.
     */
    public function isScoped(): bool
    {
        return null !== $this->projectScope->slugs();
    }

    public function getMemoryCount(): int
    {
        return $this->knowledgeReader->memoryCount();
    }

    public function getPendingProposals(): int
    {
        return $this->knowledgeReader->pendingProposals($this->project);
    }

    /**
     * @return array{documents: int, memories: int, rules: int, rules_to_review: int, files: int}|null
     */
    public function getCounts(): ?array
    {
        return null === $this->project ? null : $this->knowledgeReader->counts($this->project);
    }
}
