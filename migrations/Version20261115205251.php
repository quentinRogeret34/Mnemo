<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115205251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OAuth authorization of the MCP connectors';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector ADD mcp_authorization TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector DROP mcp_authorization');
    }
}
