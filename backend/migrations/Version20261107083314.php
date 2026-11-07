<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107083314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove legacy aspect tables after verified conversion to ordinary relation tags';
    }

    public function up(Schema $schema): void
    {
        $converter = new \App\Service\LegacyAspectConverter($this->connection, __DIR__.'/../config/aspect-catalog.json');
        $converter->convert(verifyOnly: true);
        if (!in_array('street_part_aspects', $this->connection->createSchemaManager()->listTableNames(), true)) {
            return;
        }
        $this->addSql('ALTER TABLE aspect_attributes DROP CONSTRAINT fk_6802820c81272371');
        $this->addSql('ALTER TABLE aspect_preferences DROP CONSTRAINT fk_b0db02feb6e62efa');
        $this->addSql('ALTER TABLE street_part_aspects DROP CONSTRAINT fk_67365e3efe54d947');
        $this->addSql('ALTER TABLE street_part_aspects DROP CONSTRAINT fk_67365e3e81272371');
        $this->addSql('ALTER TABLE street_part_aspects DROP CONSTRAINT fk_67365e3e7d182d95');
        $this->addSql('DROP TABLE aspect_attributes');
        $this->addSql('DROP TABLE aspect_preferences');
        $this->addSql('DROP TABLE aspect_types');
        $this->addSql('DROP TABLE street_part_aspects');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Tags remain the source of truth; restoring the prototype schema requires a backup.');
    }
}
