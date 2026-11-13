<?php

namespace App\Security;

use App\Entity\ApiKey;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which projects the caller may read and write.
 * A user reaches the projects of their groups, a super admin all of them, an API key those of its owner narrowed
 * to its own list. Without any login (local machine, console, worker) nothing is restricted.
 */
final class ProjectScope
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): ?ApiKey
    {
        $user = $this->security->getUser();

        return $user instanceof ApiKey ? $user : null;
    }

    /**
     * The person behind the request: the signed-in user, or the owner of the API key.
     */
    public function viewer(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof ApiKey ? $user->getOwner() : ($user instanceof User ? $user : null);
    }

    /**
     * @return list<string>|null slugs the caller is limited to, null when it reaches every project
     */
    public function slugs(): ?array
    {
        $viewer = $this->viewer();
        $slugs = null === $viewer || $viewer->isSuperAdmin() ? null : array_values(array_unique(array_merge(...$viewer->getGroups()->map(
            static fn (UserGroup $group): array => $group->getProjects()->map(static fn (Project $project): string => $project->getSlug())->getValues(),
        )->getValues(), ...[[]])));

        $projects = $this->key()?->getProjects();
        if (null === $projects || $projects->isEmpty()) {
            return $slugs;
        }
        $own = $projects->map(static fn (Project $project): string => $project->getSlug())->getValues();

        return null === $slugs ? $own : array_values(array_intersect($slugs, $own));
    }

    public function allows(Project $project): bool
    {
        $slugs = $this->slugs();

        return null === $slugs || \in_array($project->getSlug(), $slugs, true);
    }

    /**
     * A document or a memory shared by several projects is in reach through any of them; one attached to no project
     * belongs to the workspace, which a caller limited to some projects does not see.
     */
    public function allowsNode(Node $node): bool
    {
        return null === $this->slugs() || $node->getProjects()->exists(fn (int $key, Project $project): bool => $this->allows($project));
    }

    /**
     * @return list<Project> the projects the caller may see, by name: those in use, or the archived ones
     */
    public function projects(bool $archived = false): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(Project::class)->findBy([], ['name' => 'ASC']),
            fn (Project $project): bool => $archived === $project->isArchived() && $this->allows($project),
        ));
    }

    /**
     * The project of a "?project=slug" filter; a project out of reach does not exist for the caller.
     */
    public function bySlug(?string $slug): ?Project
    {
        if (null === $slug || '' === $slug) {
            return null;
        }
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug]);

        return null !== $project && $this->allows($project) ? $project : throw new NotFoundHttpException(\sprintf('Project "%s" not found.', $slug));
    }
}
