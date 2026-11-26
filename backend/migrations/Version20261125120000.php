<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content reset for the observation-relations model (specs/map-editing/
 * observation-relations/spec.md): existing multi-tag node relations and
 * node-tag observations predate "one observation = one node relation" and
 * the cross-section grammar lost its index segment, so all observation
 * content is deleted instead of migrated — as with the 2026-11-22 naming
 * revision. Nodes referencing them are removed with them (CASCADE); the
 * OSM provider nodes themselves stay.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deletes all observation content (node relations, group and node tags, orphan tags, aspect priorities) for the observation-relations reset.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM node_groups');
        $this->addSql('DELETE FROM node_tags');
        $this->addSql('DELETE FROM nodes n USING node_providers p WHERE n.node_provider_id = p.id AND p.name = \'local\'');
        $this->addSql('DELETE FROM tags');
        $this->addSql('DELETE FROM user_tag_priorities WHERE tag LIKE \'aspect.%\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SELECT \' observation content reset is not reversible \'');
    }
}
