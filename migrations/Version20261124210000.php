<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create PostgreSQL tables for Symfony sessions, application cache and distributed locks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                sess_id VARCHAR(128) NOT NULL,
                sess_data BYTEA NOT NULL,
                sess_lifetime INT NOT NULL,
                sess_time INT NOT NULL,
                PRIMARY KEY (sess_id)
            )
        SQL);
        $this->addSql('CREATE INDEX sess_lifetime_idx ON sessions (sess_lifetime)');

        $this->addSql(<<<'SQL'
            CREATE TABLE cache_items (
                item_id VARCHAR(255) NOT NULL,
                item_data BYTEA NOT NULL,
                item_lifetime INT DEFAULT NULL,
                item_time INT NOT NULL,
                PRIMARY KEY (item_id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE lock_keys (
                key_id VARCHAR(64) NOT NULL,
                key_token VARCHAR(44) NOT NULL,
                key_expiration INT NOT NULL,
                PRIMARY KEY (key_id)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lock_keys');
        $this->addSql('DROP TABLE cache_items');
        $this->addSql('DROP TABLE sessions');
    }
}
