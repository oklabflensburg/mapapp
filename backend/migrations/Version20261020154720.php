<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020154720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds directed parent-child relations between nodes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE node_relations (parent_id INT NOT NULL, child_id INT NOT NULL, PRIMARY KEY (parent_id, child_id), CONSTRAINT chk_node_relations_not_self CHECK (parent_id <> child_id))');
        $this->addSql('CREATE INDEX IDX_FD3B71B727ACA70 ON node_relations (parent_id)');
        $this->addSql('CREATE INDEX IDX_FD3B71BDD62C21B ON node_relations (child_id)');
        $this->addSql('ALTER TABLE node_relations ADD CONSTRAINT FK_FD3B71B727ACA70 FOREIGN KEY (parent_id) REFERENCES nodes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE node_relations ADD CONSTRAINT FK_FD3B71BDD62C21B FOREIGN KEY (child_id) REFERENCES nodes (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE node_relations DROP CONSTRAINT FK_FD3B71B727ACA70');
        $this->addSql('ALTER TABLE node_relations DROP CONSTRAINT FK_FD3B71BDD62C21B');
        $this->addSql('DROP TABLE node_relations');
    }
}
