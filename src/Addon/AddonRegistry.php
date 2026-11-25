<?php

namespace App\Addon;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class AddonRegistry
{
    /**
     * @var array<string, AddonInterface>
     */
    private array $addons = [];

    /**
     * @param iterable<AddonInterface> $addons
     */
    public function __construct(
        #[AutowireIterator('app.addon')]
        iterable $addons,
    ) {
        foreach ($addons as $addon) {
            $id = $addon->manifest()->id;
            if (isset($this->addons[$id])) {
                throw new \LogicException(\sprintf('Duplicate addon id "%s" registered.', $id));
            }
            $this->addons[$id] = $addon;
        }
    }

    /**
     * @return list<AddonInterface>
     */
    public function all(): array
    {
        return array_values($this->addons);
    }

    public function get(string $id): ?AddonInterface
    {
        return $this->addons[$id] ?? null;
    }

    /**
     * @return list<AddonInterface>
     */
    public function byCategory(?AddonCategory $category): array
    {
        if (null === $category) {
            return $this->all();
        }

        return array_values(array_filter(
            $this->addons,
            static fn (AddonInterface $addon): bool => $addon->manifest()->category === $category,
        ));
    }
}
