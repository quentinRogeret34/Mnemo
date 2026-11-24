<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password_hash column to app_user for email and password authentication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD password_hash VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP password_hash');
    }
}
