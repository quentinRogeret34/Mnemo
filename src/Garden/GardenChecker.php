<?php

namespace App\Garden;

use App\Entity\Project;
use App\Security\MemoryAccess;
use Doctrine\DBAL\Connection;

/**
 * What drifted in a project's knowledge, after AIDD's memory check (github.com/ai-driven-dev/framework, MIT):
 * it reports and changes nothing, a human decides what to fix.
 */
final class GardenChecker
{
    private const LIMIT = 50;
    private const ACTIVE_MEMORY = "m.type = 'memory' AND COALESCE(m.metadata->>'status', 'active') = 'active'";
    private const PROJECT_MEMORY = 'EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id AND np.project_id = :project)';

    public function __construct(
        private readonly Connection $connection,
        private readonly MemoryAccess $memoryAccess,
    ) {
    }

    /**
     * Findings with at least one item, in a fixed order.
     *
     * @return list<array{key: string, group: string, title: string, hint: string, count: int, items: list<array{type: string, id: int, label: string, code: ?string, path: ?string}>}>
     */
    public function check(Project $project): array
    {
        $active = self::ACTIVE_MEMORY.' AND '.$this->memoryAccess->sql('m');
        $inProject = self::PROJECT_MEMORY;
        $findings = [
            ['orphan_memories', 'memories', 'Mémoires sans projet ni lien', 'Les rattacher à un projet, les lier, ou les supprimer.', <<<SQL
                SELECT m.id, m.type, m.label, NULL AS code, NULL AS path FROM node m
                WHERE {$active} AND NOT EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = m.id)
                  AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = m.id AND e.relation = 'about' AND e.status = 'active')
                SQL],
            ['unlinked_memories', 'memories', 'Mémoires dont tous les liens ont été rejetés', 'Le sujet de la mémoire a peut-être changé : la relier ou la retirer.', <<<SQL
                SELECT m.id, m.type, m.label, NULL AS code, NULL AS path FROM node m
                WHERE {$active} AND {$inProject}
                  AND EXISTS (SELECT 1 FROM edge e WHERE e.source_id = m.id AND e.relation = 'about')
                  AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = m.id AND e.relation = 'about' AND e.status = 'active')
                SQL],
            ['unverified_memories', 'memories', 'Mémoires jamais vérifiées ou modifiées depuis', 'Lancer la vérification (bin/console app:verify).', <<<SQL
                SELECT m.id, m.type, m.label, NULL AS code, NULL AS path FROM node m
                WHERE {$active} AND {$inProject}
                  AND (m.metadata->'verification' IS NULL OR m.metadata->'verification'->>'text' IS DISTINCT FROM m.label)
                SQL],
            ['stale_memories', 'memories', 'Mémoires dont le code a changé depuis leur vérification', 'Un commit a touché un fichier lié après la vérification : relancer la vérification ou corriger la mémoire.', <<<SQL
                SELECT m.id, m.type, m.label, NULL AS code, NULL AS path FROM node m
                WHERE {$active} AND {$inProject} AND m.metadata->'verification'->>'at' IS NOT NULL
                  AND EXISTS (
                      SELECT 1 FROM edge a JOIN edge t ON t.target_id = a.target_id AND t.relation = 'touches' JOIN node c ON c.id = t.source_id
                      WHERE a.source_id = m.id AND a.relation = 'about' AND a.status = 'active'
                        AND (c.metadata->>'date')::timestamptz > (m.metadata->'verification'->>'at')::timestamptz
                  )
                SQL],
            ['rules_to_review', 'rules', 'Règles à valider', 'Relire l\'énoncé extrait et le valider.', <<<'SQL'
                SELECT r.id, r.type, r.label, r.external_id AS code, NULL AS path FROM node r
                WHERE r.project_id = :project AND r.type = 'business_rule' AND r.metadata->>'status' = 'to_review'
                SQL],
            ['rules_without_source', 'rules', 'Règles sans document source', 'Retrouver le document qui définit la règle.', <<<'SQL'
                SELECT r.id, r.type, r.label, r.external_id AS code, NULL AS path FROM node r
                WHERE r.project_id = :project AND r.type = 'business_rule'
                  AND NOT EXISTS (SELECT 1 FROM edge e JOIN node d ON d.id = e.source_id WHERE e.target_id = r.id AND d.type = 'document' AND e.status = 'active')
                SQL],
            ['rules_without_code', 'rules', 'Règles sans code relié', 'Lier le code qui implémente la règle.', <<<'SQL'
                SELECT r.id, r.type, r.label, r.external_id AS code, NULL AS path FROM node r
                WHERE r.project_id = :project AND r.type = 'business_rule'
                  AND NOT EXISTS (SELECT 1 FROM edge e WHERE e.source_id = r.id AND e.relation = 'implemented_by' AND e.status = 'active')
                SQL],
            ['rules_not_analyzed', 'rules', 'Règles jamais analysées (zones d\'ombre)', 'Lancer l\'analyse (bin/console app:rule:shadow).', <<<'SQL'
                SELECT r.id, r.type, r.label, r.external_id AS code, NULL AS path FROM node r
                WHERE r.project_id = :project AND r.type = 'business_rule' AND r.metadata->>'shadow_checked_at' IS NULL
                SQL],
            ['isolated_documents', 'documents', 'Documents reliés à rien', 'Ni règle, ni code, ni mémoire : les relier ou les détacher du projet.', <<<'SQL'
                SELECT d.id, d.type, d.label, NULL AS code, d.path FROM node d
                WHERE d.type = 'document' AND EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = d.id AND np.project_id = :project)
                  AND NOT EXISTS (SELECT 1 FROM edge e WHERE (e.source_id = d.id OR e.target_id = d.id) AND e.status = 'active')
                SQL],
        ];

        $result = [];
        foreach ($findings as [$key, $group, $title, $hint, $sql]) {
            $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ($sql) found", ['project' => $project->getId()]);
            if (0 === $count) {
                continue;
            }
            $items = $this->connection->fetchAllAssociative("SELECT * FROM ($sql) found ORDER BY label LIMIT ".self::LIMIT, ['project' => $project->getId()]);
            $result[] = ['key' => $key, 'group' => $group, 'title' => $title, 'hint' => $hint, 'count' => $count, 'items' => array_map(static fn (array $row): array => ['id' => (int) $row['id']] + $row, $items)];
        }

        $inferred = $this->inferredEdges($project);
        if ([] !== $inferred) {
            $result[] = [
                'key' => 'inferred_edges',
                'group' => 'links',
                'title' => 'Liens inférés jamais validés',
                'hint' => 'Proposés par le LLM : les confirmer ou les délier depuis les fiches.',
                'count' => array_sum(array_column($inferred, 'count')),
                'items' => array_map(static fn (array $row): array => ['type' => 'relation', 'id' => 0, 'label' => \sprintf('%s : %d', $row['relation'], $row['count']), 'code' => null, 'path' => null], $inferred),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{relation: string, count: int}>
     */
    private function inferredEdges(Project $project): array
    {
        return array_map(
            static fn (array $row): array => ['relation' => $row['relation'], 'count' => (int) $row['count']],
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT e.relation, COUNT(*) AS count FROM edge e
                    WHERE e.status = 'active' AND e.confidence = 'INFERRED'
                      AND EXISTS (
                          SELECT 1 FROM node n WHERE n.id IN (e.source_id, e.target_id)
                            AND (n.project_id = :project OR EXISTS (SELECT 1 FROM node_project np WHERE np.node_id = n.id AND np.project_id = :project))
                      )
                    GROUP BY e.relation ORDER BY e.relation
                    SQL,
                ['project' => $project->getId()],
            ),
        );
    }
}
