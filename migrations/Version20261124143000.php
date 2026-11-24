<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add opt-in auto_garden flag and last_auto_garden_at timestamp to project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD auto_garden BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE project ADD last_auto_garden_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP last_auto_garden_at');
        $this->addSql('ALTER TABLE project DROP auto_garden');
    }
}
