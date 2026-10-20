<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'node_relations')]
class NodeRelation
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'childRelations')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Node $parent,

        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'parentRelations')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Node $child,
    ) {
        if ($parent === $child) {
            throw new \InvalidArgumentException('A node cannot be its own parent.');
        }
    }

    public function getParent(): Node
    {
        return $this->parent;
    }

    public function getChild(): Node
    {
        return $this->child;
    }
}
