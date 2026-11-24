<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encrypted outbound notification webhook columns to project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD webhook_url_encrypted TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE project ADD webhook_format VARCHAR(20) DEFAULT 'slack' NOT NULL");
        $this->addSql('ALTER TABLE project ADD webhook_events JSONB DEFAULT \'["shadow_blocker","pr_contradiction"]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP webhook_url_encrypted');
        $this->addSql('ALTER TABLE project DROP webhook_format');
        $this->addSql('ALTER TABLE project DROP webhook_events');
    }
}
