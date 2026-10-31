<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Store proposed tag translations until an admin approves them. */
final class Version20261031084208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add proposed translations to tags, with empty proposals for existing tags.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tags ADD translations JSON NOT NULL DEFAULT '{}' ");
        $this->addSql('ALTER TABLE tags ALTER COLUMN translations DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tags DROP translations');
    }
}
