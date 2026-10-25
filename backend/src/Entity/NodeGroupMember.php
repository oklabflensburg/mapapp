<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** An ordered member node of a node relation. */
#[ORM\Entity]
#[ORM\Table(name: 'node_group_members')]
#[ORM\UniqueConstraint(name: 'uq_node_group_members_group_node', columns: ['group_id', 'node_id'])]
class NodeGroupMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: NodeGroup::class, inversedBy: 'members')]
        #[ORM\JoinColumn(name: 'group_id', nullable: false, onDelete: 'CASCADE')]
        private readonly NodeGroup $group,

        #[ORM\ManyToOne(targetEntity: Node::class)]
        #[ORM\JoinColumn(name: 'node_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Node $node,

        #[ORM\Column]
        private readonly int $position,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroup(): NodeGroup
    {
        return $this->group;
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
