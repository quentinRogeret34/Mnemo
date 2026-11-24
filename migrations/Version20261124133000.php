<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow chat_conversation.project_id to be NULL for global workspace assistant conversations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_conversation ALTER project_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM chat_conversation WHERE project_id IS NULL');
        $this->addSql('ALTER TABLE chat_conversation ALTER project_id SET NOT NULL');
    }
}
