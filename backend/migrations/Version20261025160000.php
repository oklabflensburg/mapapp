<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the tables of the prototype that preceded this project (map features
 * with captured accessibility observations, media attachments, sensor
 * measurements and routing preferences). No entity or migration of this
 * project ever referenced them; they only linger in database volumes the
 * prototype already used, so every statement is idempotent.
 */
final class Version20261025160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drops the unused prototype tables (observations, map_features, map_feature_types, media, sensor_measurements, routing_preferences).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS observations CASCADE');
        $this->addSql('DROP TABLE IF EXISTS map_features CASCADE');
        $this->addSql('DROP TABLE IF EXISTS map_feature_types CASCADE');
        $this->addSql('DROP TABLE IF EXISTS media CASCADE');
        $this->addSql('DROP TABLE IF EXISTS sensor_measurements CASCADE');
        $this->addSql('DROP TABLE IF EXISTS routing_preferences CASCADE');
    }

    public function down(Schema $schema): void
    {
        // The prototype schema is gone and nothing in this project uses it;
        // the dropped data cannot and should not be restored.
        $this->skipIf(true, 'The prototype tables are not part of this project.');
    }
}
