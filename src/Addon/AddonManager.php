<?php

namespace App\Addon;

use App\Entity\AddonInstallation;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

final class AddonManager
{
    public const PRESET_BUSINESS = 'business';
    public const PRESET_FACTORY = 'factory';
    public const PRESETS = [self::PRESET_BUSINESS, self::PRESET_FACTORY];

    public function __construct(
        private readonly AddonRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<AddonInterface>
     */
    public function all(): array
    {
        return $this->registry->all();
    }

    public function get(string $id): ?AddonInterface
    {
        return $this->registry->get($id);
    }

    /**
     * @return list<AddonInterface>
     */
    public function byCategory(?AddonCategory $category): array
    {
        return $this->registry->byCategory($category);
    }

    public function isEnabled(string $addonId, ?Project $project = null): bool
    {
        $addon = $this->registry->get($addonId);
        if (null === $addon) {
            return false;
        }

        if (null !== $project && null !== $project->getId()) {
            $projectInstallation = $this->findInstallation($addonId, $project);
            if (null !== $projectInstallation) {
                return $projectInstallation->isEnabled();
            }
        }

        $workspaceInstallation = $this->findInstallation($addonId, null);
        if (null !== $workspaceInstallation) {
            return $workspaceInstallation->isEnabled();
        }

        return $addon->manifest()->defaultEnabled;
    }

    public function hasProjectOverride(string $addonId, Project $project): bool
    {
        return null !== $project->getId() && null !== $this->findInstallation($addonId, $project);
    }

    public function setEnabled(string $addonId, bool $enabled, ?Project $project = null): AddonInstallation
    {
        $addon = $this->registry->get($addonId);
        if (null === $addon) {
            throw new \InvalidArgumentException(\sprintf('Unknown addon "%s".', $addonId));
        }

        if (null !== $project && AddonScope::Workspace === $addon->manifest()->scope) {
            throw new \InvalidArgumentException(\sprintf('Addon "%s" is workspace-scoped and cannot be configured per project.', $addonId));
        }

        $installation = $this->upsertInstallation($addonId, $enabled, $project);
        $this->entityManager->flush();

        return $installation;
    }

    public function applyPreset(string $preset, ?Project $project = null): void
    {
        if (!\in_array($preset, self::PRESETS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown addon preset "%s".', $preset));
        }

        foreach ($this->registry->all() as $addon) {
            $manifest = $addon->manifest();
            if (null !== $project && AddonScope::Workspace === $manifest->scope) {
                continue;
            }

            $enabled = self::PRESET_FACTORY === $preset
                || \in_array($preset, $manifest->presets, true)
                || ([] === $manifest->presets && AddonCategory::Factory !== $manifest->category);

            $this->upsertInstallation($manifest->id, $enabled, $project);
        }

        $this->entityManager->flush();
    }

    /**
     * @return list<AddonNavItem>
     */
    public function navItemsFor(string $group, ?Project $project = null): array
    {
        $items = [];
        $wantProjectScoped = null !== $project;

        foreach ($this->registry->all() as $addon) {
            $manifest = $addon->manifest();
            if (!$this->isEnabled($manifest->id, $project)) {
                continue;
            }

            foreach ($manifest->navItems as $item) {
                if ($item->group === $group && $item->projectScoped === $wantProjectScoped) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function findInstallation(string $addonId, ?Project $project): ?AddonInstallation
    {
        return $this->entityManager->getRepository(AddonInstallation::class)->findOneBy([
            'addonId' => $addonId,
            'project' => $project,
        ]);
    }

    private function upsertInstallation(string $addonId, bool $enabled, ?Project $project): AddonInstallation
    {
        $installation = $this->findInstallation($addonId, $project);
        if (null === $installation) {
            $installation = new AddonInstallation($addonId, $project, $enabled);
            $this->entityManager->persist($installation);

            return $installation;
        }

        $installation->setEnabled($enabled);

        return $installation;
    }
}
