<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the hashed password column to users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD password VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE users ALTER COLUMN password DROP DEFAULT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN password');
    }
}
