<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the nullable osm_road_class column to route_preference_tags, making the OSM road class mapping a per-tag admin setting, seeded with the previously hardcoded stairs mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE route_preference_tags ADD osm_road_class VARCHAR(30) DEFAULT NULL');
        // The mapping that was hardcoded in Routing::customModelFor()
        // (stairs => road_class STEPS) before it became admin-managed.
        $this->addSql('UPDATE route_preference_tags SET osm_road_class = \'STEPS\' WHERE tag = \'stairs\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE route_preference_tags DROP osm_road_class');
    }
}
