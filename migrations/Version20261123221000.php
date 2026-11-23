<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123221000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert node.metadata and proposal.payload to JSONB and add missing performance and uniqueness indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE node ALTER metadata TYPE JSONB USING metadata::jsonb');
        $this->addSql('ALTER TABLE proposal ALTER payload TYPE JSONB USING payload::jsonb');
        // Deduplicate any pre-existing workspace-level nodes sharing (type, external_id) before enforcing uniqueness.
        $this->addSql('DELETE FROM node a USING node b WHERE a.id > b.id AND a.project_id IS NULL AND b.project_id IS NULL AND a.type = b.type AND a.external_id = b.external_id');
        $this->addSql('CREATE UNIQUE INDEX node_workspace_external_id_unique ON node (type, external_id) WHERE (project_id IS NULL)');
        $this->addSql('CREATE INDEX node_type_updated_idx ON node (type, updated_at)');
        $this->addSql('CREATE INDEX node_type_created_idx ON node (type, created_at)');
        $this->addSql('CREATE INDEX proposal_status_project_idx ON proposal (status, project_id)');
        $this->addSql('CREATE INDEX mcp_call_project_created_idx ON mcp_call (project, created_at)');
        $this->addSql('CREATE INDEX node_embedding_model_idx ON node_embedding (model)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX node_embedding_model_idx');
        $this->addSql('DROP INDEX mcp_call_project_created_idx');
        $this->addSql('DROP INDEX proposal_status_project_idx');
        $this->addSql('DROP INDEX node_type_created_idx');
        $this->addSql('DROP INDEX node_type_updated_idx');
        $this->addSql('DROP INDEX node_workspace_external_id_unique');
        $this->addSql('ALTER TABLE proposal ALTER payload TYPE JSON USING payload::json');
        $this->addSql('ALTER TABLE node ALTER metadata TYPE JSON USING metadata::json');
    }
}
