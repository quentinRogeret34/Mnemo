<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126203403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add archived_at column to swarm_run and agent_session';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_session ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE swarm_run ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_session DROP archived_at');
        $this->addSql('ALTER TABLE swarm_run DROP archived_at');
    }
}
