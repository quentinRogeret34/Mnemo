<?php

namespace App\Repository;

use App\Entity\Folder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Folder>
 */
final class FolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Folder::class);
    }

    public function existsWithNameInParent(string $name, ?Folder $parent): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM folder WHERE lower(name) = lower(:name) AND '.(null === $parent ? 'parent_id IS NULL' : 'parent_id = :parent').' LIMIT 1',
            ['name' => $name] + (null === $parent ? [] : ['parent' => $parent->getId()]),
        );
    }
}
