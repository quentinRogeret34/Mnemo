<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deduplicate folders and enforce unique folder names per parent';
    }

    public function up(Schema $schema): void
    {
        // Merging two folders can make their children collide in turn, hence the loop until no duplicate is left.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                LOOP
                    CREATE TEMP TABLE folder_dupes ON COMMIT DROP AS
                        SELECT id, keep_id FROM (
                            SELECT id, MIN(id) OVER (PARTITION BY parent_id, lower(name)) AS keep_id FROM folder
                        ) d WHERE id <> keep_id;
                    IF NOT EXISTS (SELECT 1 FROM folder_dupes) THEN
                        DROP TABLE folder_dupes;
                        EXIT;
                    END IF;
                    UPDATE folder f SET parent_id = d.keep_id FROM folder_dupes d WHERE f.parent_id = d.id;
                    UPDATE node n SET folder_id = d.keep_id FROM folder_dupes d WHERE n.folder_id = d.id;
                    UPDATE connector c SET folder_id = d.keep_id FROM folder_dupes d WHERE c.folder_id = d.id;
                    DELETE FROM folder f USING folder_dupes d WHERE f.id = d.id;
                    DROP TABLE folder_dupes;
                END LOOP;
            END $$
            SQL);
        $this->addSql('CREATE UNIQUE INDEX folder_root_name_unique ON folder (name) WHERE parent_id IS NULL');
        $this->addSql('CREATE UNIQUE INDEX folder_parent_name_unique ON folder (parent_id, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX folder_root_name_unique');
        $this->addSql('DROP INDEX folder_parent_name_unique');
    }
}
