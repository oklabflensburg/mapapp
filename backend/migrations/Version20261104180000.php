<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Node approval is derived from tag approval: publish nodes that already carry an approved tag.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE nodes n SET approved = true WHERE NOT n.approved AND EXISTS (SELECT 1 FROM node_tags nt WHERE nt.node_id = n.id AND nt.approved = true)');
    }

    public function down(Schema $schema): void
    {
        // The derived state cannot be restored; nodes return to their current value.
    }
}
