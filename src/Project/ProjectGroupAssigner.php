<?php

namespace App\Project;

use App\Entity\Project;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronizes a project's membership across UserGroup entities (not flushed).
 */
final readonly class ProjectGroupAssigner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<UserGroup>
     */
    public function groupsOf(Project $project): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(UserGroup::class)->findAll(),
            static fn (UserGroup $group): bool => $group->getProjects()->contains($project),
        ));
    }

    /**
     * @param iterable<UserGroup> $chosen
     */
    public function sync(Project $project, iterable $chosen): void
    {
        $selected = [...$chosen];
        foreach ($this->entityManager->getRepository(UserGroup::class)->findAll() as $group) {
            $member = $group->getProjects()->contains($project);
            if (\in_array($group, $selected, true) && !$member) {
                $group->getProjects()->add($project);
            } elseif (!\in_array($group, $selected, true) && $member) {
                $group->getProjects()->removeElement($project);
            }
        }
    }
}
