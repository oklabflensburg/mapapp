<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the per-tag list of default values to the admin-managed default
 * observation tags: the values users can pick for a key in the frontend,
 * served through /api/default-observation-tags.
 */
final class Version20261028210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds default_values to default_observation_tags for admin-managed selectable values.';
    }

    public function up(Schema $schema): void
    {
        // The temporary default backfills existing rows with an empty value
        // list; dropping it again keeps the column in sync with the mapping.
        $this->addSql("ALTER TABLE default_observation_tags ADD default_values JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE default_observation_tags ALTER COLUMN default_values DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE default_observation_tags DROP default_values');
    }
}
