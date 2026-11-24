<?php

namespace App\Repository;

/**
 * Shared PostgreSQL JSONB predicates to avoid duplicating node metadata filters across repositories.
 */
final class NodeSql
{
    public static function activeMemory(string $alias = 'm'): string
    {
        return \sprintf("COALESCE(%s.metadata->>'status', 'active') = 'active'", $alias);
    }
}
