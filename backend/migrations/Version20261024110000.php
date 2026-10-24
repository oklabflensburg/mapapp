<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the approved column to tags.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tags ADD approved BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE tags ALTER COLUMN approved DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tags DROP COLUMN approved');
    }
}
