<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114174108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LLM key and models set from the interface, models per project, one embedding per node and model';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE setting (name VARCHAR(100) NOT NULL, value TEXT NOT NULL, PRIMARY KEY (name))');
        $this->addSql('ALTER TABLE project ADD chat_model VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD embedding_model VARCHAR(150) DEFAULT NULL');
        // A node shared by projects that name different embedding models keeps one vector per model.
        $this->addSql('ALTER TABLE node_embedding DROP CONSTRAINT node_embedding_pkey');
        $this->addSql('ALTER TABLE node_embedding ADD PRIMARY KEY (node_id, model)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DELETE FROM node_embedding a USING node_embedding b WHERE a.node_id = b.node_id AND a.model > b.model');
        $this->addSql('ALTER TABLE node_embedding DROP CONSTRAINT node_embedding_pkey');
        $this->addSql('ALTER TABLE node_embedding ADD PRIMARY KEY (node_id)');
        $this->addSql('DROP TABLE setting');
        $this->addSql('ALTER TABLE project DROP chat_model');
        $this->addSql('ALTER TABLE project DROP embedding_model');
    }
}
