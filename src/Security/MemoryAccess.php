<?php

namespace App\Security;

use App\Entity\ApiKey;
use App\Entity\Node;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\MemoryVisibility;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who sees a memory. Its metadata holds "visibility" (project by default, group or private), "owner_id" and "group_id".
 * A project memory follows the project; a group memory is for the members of one group; a private one for its author.
 * Super admins, and anything running without a login (local machine, console, worker), see every memory.
 */
final class MemoryAccess
{
    public function __construct(
        private readonly Security $security,
        private readonly ProjectScope $projectScope,
    ) {
    }

    /**
     * SQL condition on a memory node, to append to every query that lists memories. Only integers are inlined.
     */
    public function sql(string $alias): string
    {
        if (!$this->isRestricted()) {
            return 'TRUE';
        }
        $shared = "COALESCE({$alias}.metadata->>'visibility', 'project') = 'project'";
        $viewer = $this->projectScope->viewer();
        if (null === $viewer) {
            return "({$shared})";
        }
        $groups = implode(', ', [0, ...$viewer->getGroups()->map(static fn (UserGroup $group): int => (int) $group->getId())->getValues()]);

        return \sprintf(
            "(%s OR (%s.metadata->>'visibility' = 'private' AND (%2\$s.metadata->>'owner_id')::int = %d) OR (%2\$s.metadata->>'visibility' = 'group' AND (%2\$s.metadata->>'group_id')::int IN (%s)))",
            $shared, $alias, (int) $viewer->getId(), $groups,
        );
    }

    /**
     * Condition keeping only the memories shared with the whole project: what an LLM pass may mix together.
     */
    public function sharedSql(string $alias): string
    {
        return "COALESCE({$alias}.metadata->>'visibility', 'project') = 'project'";
    }

    public function canSee(Node $memory): bool
    {
        if (!$this->isRestricted()) {
            return true;
        }
        $metadata = $memory->getMetadata();
        $viewer = $this->projectScope->viewer();
        $visible = match (MemoryVisibility::tryFrom((string) ($metadata['visibility'] ?? 'project'))) {
            MemoryVisibility::Personal => null !== $viewer && (int) ($metadata['owner_id'] ?? 0) === $viewer->getId(),
            MemoryVisibility::Group => null !== $viewer && $viewer->getGroups()->exists(static fn (int $key, UserGroup $group): bool => $group->getId() === (int) ($metadata['group_id'] ?? 0)),
            default => true,
        };

        return $visible && $this->projectScope->allowsNode($memory);
    }

    /**
     * Metadata for a memory the caller creates. A group must be one of the caller's; a private memory needs an author.
     *
     * @return array<string, mixed>
     */
    public function metadataFor(MemoryVisibility $visibility, ?int $groupId = null): array
    {
        $viewer = $this->projectScope->viewer();
        $owner = null === $viewer ? [] : ['owner_id' => $viewer->getId()];

        return match ($visibility) {
            MemoryVisibility::Project => $owner,
            MemoryVisibility::Personal => null === $viewer
                ? throw new \InvalidArgumentException('Une mémoire privée a besoin d\'un auteur : se connecter, ou utiliser une clé d\'API rattachée à un utilisateur.') : $owner + ['visibility' => MemoryVisibility::Personal->value],
            MemoryVisibility::Group => null !== $groupId && (null === $viewer || $viewer->isSuperAdmin() || $viewer->getGroups()->exists(static fn (int $key, UserGroup $group): bool => $group->getId() === $groupId))
                ? $owner + ['visibility' => MemoryVisibility::Group->value, 'group_id' => $groupId]
                : throw new \InvalidArgumentException('Choisir un de ses groupes pour une mémoire de groupe.'),
        };
    }

    private function isRestricted(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof ApiKey || ($user instanceof User && !$user->isSuperAdmin());
    }
}
