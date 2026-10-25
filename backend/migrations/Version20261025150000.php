<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the nullable name column to node groups, naming e.g. the road part a node relation covers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE node_groups ADD name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE node_groups DROP name');
    }
}
