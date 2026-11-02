<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102204003 extends AbstractMigration
{
    private const ROOTS = <<<'SQL'
WITH RECURSIVE roots(root_id, child_id) AS (
    SELECT parent_id, child_id FROM node_relations relation
    WHERE NOT EXISTS (SELECT 1 FROM node_relations incoming WHERE incoming.child_id = relation.parent_id)
    UNION
    SELECT roots.root_id, relation.child_id FROM roots
    JOIN node_relations relation ON relation.parent_id = roots.child_id
)
SQL;

    public function getDescription(): string
    {
        return 'Replace node relations with a single parent reference and flatten descendants to their root.';
    }

    public function up(Schema $schema): void
    {
        // Do not silently choose a parent when the old graph has multiple roots
        // or cycles. Those records need an explicit data correction first.
        $invalid = $this->connection->fetchOne(self::ROOTS.<<<'SQL'
SELECT COUNT(*) FROM (
    SELECT relation.child_id FROM node_relations relation
    LEFT JOIN roots ON roots.child_id = relation.child_id
    GROUP BY relation.child_id HAVING COUNT(DISTINCT roots.root_id) <> 1
) invalid
SQL);
        $this->abortIf((int) $invalid > 0, 'Resolve cycles or nodes with multiple root parents before migrating.');
        $osmChildren = $this->connection->fetchOne("SELECT COUNT(*) FROM node_relations r JOIN nodes n ON n.id = r.child_id JOIN node_providers p ON p.id = n.node_provider_id WHERE p.name <> 'local'");
        $this->abortIf((int) $osmChildren > 0, 'Only custom nodes may be children; correct provider node relations before migrating.');

        $this->addSql('ALTER TABLE nodes ADD parent_id INT DEFAULT NULL');
        $this->addSql(self::ROOTS.' UPDATE nodes SET parent_id = roots.root_id FROM roots WHERE nodes.id = roots.child_id');
        $this->addSql('ALTER TABLE nodes ADD CONSTRAINT FK_1D3D05FC727ACA70 FOREIGN KEY (parent_id) REFERENCES nodes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1D3D05FC727ACA70 ON nodes (parent_id)');
        $this->addSql('DROP TABLE node_relations');
    }

    public function down(Schema $schema): void
    {
        // Restores the relation representation of the current flat hierarchy.
        $this->addSql('CREATE TABLE node_relations (parent_id INT NOT NULL, child_id INT NOT NULL, PRIMARY KEY (parent_id, child_id), CONSTRAINT chk_node_relations_not_self CHECK (parent_id <> child_id))');
        $this->addSql('CREATE INDEX IDX_FD3B71B727ACA70 ON node_relations (parent_id)');
        $this->addSql('CREATE INDEX IDX_FD3B71BDD62C21B ON node_relations (child_id)');
        $this->addSql('ALTER TABLE node_relations ADD CONSTRAINT FK_FD3B71B727ACA70 FOREIGN KEY (parent_id) REFERENCES nodes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE node_relations ADD CONSTRAINT FK_FD3B71BDD62C21B FOREIGN KEY (child_id) REFERENCES nodes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('INSERT INTO node_relations (parent_id, child_id) SELECT parent_id, id FROM nodes WHERE parent_id IS NOT NULL');
        $this->addSql('ALTER TABLE nodes DROP CONSTRAINT FK_1D3D05FC727ACA70');
        $this->addSql('DROP INDEX IDX_1D3D05FC727ACA70');
        $this->addSql('ALTER TABLE nodes DROP parent_id');
    }
}
