<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102212215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add independent approval for custom nodes, retaining pending nodes in the review queue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nodes ADD approved BOOLEAN DEFAULT true NOT NULL');
        // Preserve published nodes; custom nodes that were only visible to
        // their author still require review, including those without tags.
        $this->addSql("UPDATE nodes n SET approved = false FROM node_providers p WHERE n.node_provider_id = p.id AND p.name = 'local' AND NOT EXISTS (SELECT 1 FROM node_tags nt WHERE nt.node_id = n.id AND nt.approved = true)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nodes DROP approved');
    }
}
