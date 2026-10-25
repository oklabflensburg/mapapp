<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Approval moves from the shared tag to the node and node relation it was
 * added on: node_tags and node_group_tags gain an approved column, the
 * tags.approved column is dropped. Rows that referenced an approved tag are
 * backfilled as approved so everything visible today stays visible; from now
 * on approving a value on one node or relation never approves it on another.
 */
final class Version20261025170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Moves tag approval from the shared tags table to the node_tags and node_group_tags rows (per tag and per node/relation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE node_tags ADD approved BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE node_group_tags ADD approved BOOLEAN NOT NULL DEFAULT false');
        // Preserve the current visible state: under the old model rows
        // referencing an approved tag were approved everywhere.
        $this->addSql(
            'UPDATE node_tags SET approved = true FROM tags'
            .' WHERE node_tags.tag_id = tags.id AND tags.approved = true',
        );
        $this->addSql(
            'UPDATE node_group_tags SET approved = true FROM tags'
            .' WHERE node_group_tags.tag_id = tags.id AND tags.approved = true',
        );
        $this->addSql('ALTER TABLE tags DROP COLUMN approved');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tags ADD approved BOOLEAN NOT NULL DEFAULT false');
        // Best-effort inverse of the backfill: a tag counts as approved when
        // it is approved on at least one node or relation.
        $this->addSql(
            'UPDATE tags SET approved = true'
            .' WHERE EXISTS (SELECT 1 FROM node_tags WHERE node_tags.tag_id = tags.id AND node_tags.approved = true)'
            .' OR EXISTS (SELECT 1 FROM node_group_tags WHERE node_group_tags.tag_id = tags.id AND node_group_tags.approved = true)',
        );
        $this->addSql('ALTER TABLE node_tags DROP COLUMN approved');
        $this->addSql('ALTER TABLE node_group_tags DROP COLUMN approved');
    }
}
