<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107073924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reserved prototype version; aspects now use existing tags';
    }

    public function up(Schema $schema): void
    {
        // Superseded before release. Fresh installations use ordinary tags.
        // Already-applied prototype tables are handled by Version20261107083314.
    }

    public function down(Schema $schema): void
    {
        // No schema is created by this version on fresh installations.
    }
}
