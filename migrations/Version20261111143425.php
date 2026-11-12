<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111143425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Embeddings of memories, rules and documents (pgvector), and the search mode of MCP calls';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mcp_call ADD mode VARCHAR(10) DEFAULT NULL');
        // No dimension on the column: the model can change without a migration. Exact search is enough at this size.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->addSql('CREATE TABLE node_embedding (node_id INT PRIMARY KEY REFERENCES node (id) ON DELETE CASCADE, model VARCHAR(150) NOT NULL, content_hash VARCHAR(64) NOT NULL, embedding vector NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mcp_call DROP mode');
        $this->addSql('DROP TABLE node_embedding');
    }
}
