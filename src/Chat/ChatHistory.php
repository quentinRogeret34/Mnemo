<?php

namespace App\Chat;

use App\Entity\ChatConversation;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\UuidV7;

/**
 * Manages the persisted chat conversations of the signed-in user (or local/admin visitor) on a project.
 */
final class ChatHistory
{
    public const MAX_CONVERSATIONS = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function ownerKey(): string
    {
        $user = $this->security->getUser();
        if ($user instanceof User && null !== $user->getId()) {
            return 'user:'.$user->getId();
        }
        if (null !== $user) {
            return 'account:'.substr($user->getUserIdentifier(), 0, 55);
        }

        return 'local';
    }

    /**
     * @return list<ChatConversation>
     */
    public function list(Project $project, string $search = ''): array
    {
        $search = trim($search);
        $ownerKey = $this->ownerKey();

        if ('' === $search) {
            return $this->entityManager->createQueryBuilder()
                ->select('c')
                ->from(ChatConversation::class, 'c')
                ->where('c.project = :project')
                ->andWhere('c.ownerKey = :owner')
                ->setParameter('project', $project)
                ->setParameter('owner', $ownerKey)
                ->orderBy('c.updatedAt', 'DESC')
                ->addOrderBy('c.id', 'DESC')
                ->setMaxResults(self::MAX_CONVERSATIONS)
                ->getQuery()
                ->getResult();
        }

        $escaped = addcslashes($search, '%_\\');
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM chat_conversation WHERE project_id = :project AND owner_key = :owner AND (title ILIKE :q OR preview ILIKE :q OR messages::text ILIKE :q) ORDER BY updated_at DESC, id DESC LIMIT :limit',
            [
                'project' => $project->getId(),
                'owner' => $ownerKey,
                'q' => '%'.$escaped.'%',
                'limit' => self::MAX_CONVERSATIONS,
            ],
        );

        if ([] === $ids) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(ChatConversation::class, 'c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_map('intval', $ids))
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function count(Project $project): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(ChatConversation::class, 'c')
            ->where('c.project = :project')
            ->andWhere('c.ownerKey = :owner')
            ->setParameter('project', $project)
            ->setParameter('owner', $this->ownerKey())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function find(Project $project, int $id): ?ChatConversation
    {
        return $this->entityManager->getRepository(ChatConversation::class)->findOneBy([
            'id' => $id,
            'project' => $project,
            'ownerKey' => $this->ownerKey(),
        ]);
    }

    public function create(Project $project, string $firstPrompt, ?UuidV7 $uuid = null): ChatConversation
    {
        $conversation = new ChatConversation($project, $this->ownerKey(), $firstPrompt, $uuid);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        $this->pruneOverflow($project);

        return $conversation;
    }

    public function delete(Project $project, int $id): bool
    {
        $conversation = $this->find($project, $id);
        if (null === $conversation) {
            return false;
        }
        $this->entityManager->remove($conversation);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param list<int> $ids
     */
    public function deleteMany(Project $project, array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->delete(ChatConversation::class, 'c')
            ->where('c.project = :project')
            ->andWhere('c.ownerKey = :owner')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('project', $project)
            ->setParameter('owner', $this->ownerKey())
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function clear(Project $project): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->delete(ChatConversation::class, 'c')
            ->where('c.project = :project')
            ->andWhere('c.ownerKey = :owner')
            ->setParameter('project', $project)
            ->setParameter('owner', $this->ownerKey())
            ->getQuery()
            ->execute();
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }

    private function pruneOverflow(Project $project): void
    {
        $staleIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM chat_conversation WHERE project_id = :project AND owner_key = :owner ORDER BY updated_at DESC, id DESC OFFSET :offset',
            [
                'project' => $project->getId(),
                'owner' => $this->ownerKey(),
                'offset' => self::MAX_CONVERSATIONS,
            ],
        );
        if ([] !== $staleIds) {
            $this->deleteMany($project, array_map('intval', $staleIds));
        }
    }
}
