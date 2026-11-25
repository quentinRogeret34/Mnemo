<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125224500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rounds column to grooming_run for multi-round 3-agent grooming debates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE grooming_run ADD rounds INT DEFAULT 2 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE grooming_run DROP rounds');
    }
}
