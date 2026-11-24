<?php

namespace App\Chat;

use App\Entity\ChatConversation;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\UuidV7;

/**
 * Manages the persisted chat conversations of the signed-in user (or local/admin visitor) on a project
 * or across the whole workspace (when $project is null).
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
    public function list(?Project $project, string $search = ''): array
    {
        $search = trim($search);
        $ownerKey = $this->ownerKey();

        if ('' === $search) {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('c')
                ->from(ChatConversation::class, 'c')
                ->where('c.ownerKey = :owner')
                ->setParameter('owner', $ownerKey)
                ->orderBy('c.updatedAt', 'DESC')
                ->addOrderBy('c.id', 'DESC')
                ->setMaxResults(self::MAX_CONVERSATIONS);
            $this->applyProjectFilter($qb, $project);

            return $qb->getQuery()->getResult();
        }

        $escaped = addcslashes($search, '%_\\');
        $projectSql = null === $project ? 'project_id IS NULL' : 'project_id = :project';
        $params = [
            'owner' => $ownerKey,
            'q' => '%'.$escaped.'%',
            'limit' => self::MAX_CONVERSATIONS,
        ];
        if (null !== $project) {
            $params['project'] = $project->getId();
        }

        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT id FROM chat_conversation WHERE {$projectSql} AND owner_key = :owner AND (title ILIKE :q OR preview ILIKE :q OR messages::text ILIKE :q) ORDER BY updated_at DESC, id DESC LIMIT :limit",
            $params,
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

    public function count(?Project $project): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(ChatConversation::class, 'c')
            ->where('c.ownerKey = :owner')
            ->setParameter('owner', $this->ownerKey());
        $this->applyProjectFilter($qb, $project);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function find(?Project $project, int $id): ?ChatConversation
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(ChatConversation::class, 'c')
            ->where('c.id = :id')
            ->andWhere('c.ownerKey = :owner')
            ->setParameter('id', $id)
            ->setParameter('owner', $this->ownerKey());
        $this->applyProjectFilter($qb, $project);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function create(?Project $project, string $firstPrompt, ?UuidV7 $uuid = null): ChatConversation
    {
        $conversation = new ChatConversation($project, $this->ownerKey(), $firstPrompt, $uuid);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        $this->pruneOverflow($project);

        return $conversation;
    }

    public function delete(?Project $project, int $id): bool
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
    public function deleteMany(?Project $project, array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ([] === $ids) {
            return 0;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->delete(ChatConversation::class, 'c')
            ->where('c.ownerKey = :owner')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('owner', $this->ownerKey())
            ->setParameter('ids', $ids);
        $this->applyProjectFilter($qb, $project);

        return (int) $qb->getQuery()->execute();
    }

    public function clear(?Project $project): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(ChatConversation::class, 'c')
            ->where('c.ownerKey = :owner')
            ->setParameter('owner', $this->ownerKey());
        $this->applyProjectFilter($qb, $project);

        return (int) $qb->getQuery()->execute();
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }

    private function applyProjectFilter(QueryBuilder $qb, ?Project $project): void
    {
        if (null === $project) {
            $qb->andWhere('c.project IS NULL');
        } else {
            $qb->andWhere('c.project = :project')->setParameter('project', $project);
        }
    }

    private function pruneOverflow(?Project $project): void
    {
        $projectSql = null === $project ? 'project_id IS NULL' : 'project_id = :project';
        $params = [
            'owner' => $this->ownerKey(),
            'offset' => self::MAX_CONVERSATIONS,
        ];
        if (null !== $project) {
            $params['project'] = $project->getId();
        }

        $staleIds = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT id FROM chat_conversation WHERE {$projectSql} AND owner_key = :owner ORDER BY updated_at DESC, id DESC OFFSET :offset",
            $params,
        );
        if ([] !== $staleIds) {
            $this->deleteMany($project, array_map('intval', $staleIds));
        }
    }
}
