<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Attributes a tag on a node to the user who added it. The same tag can be
 * added to the same node by multiple users, once per user. Approval is per
 * tag and per node: it lives here, not on the shared tag, so approving a
 * value on one node never approves it on another.
 */
#[ORM\Entity]
#[ORM\Table(name: 'node_tags')]
#[ORM\UniqueConstraint(name: 'uq_node_tags_node_tag_user', columns: ['node_id', 'tag_id', 'created_by_user_id'])]
class NodeTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $approved = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Node::class, inversedBy: 'nodeTags')]
        #[ORM\JoinColumn(name: 'node_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Node $node,

        #[ORM\ManyToOne(targetEntity: Tag::class, inversedBy: 'nodeTags')]
        #[ORM\JoinColumn(name: 'tag_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Tag $tag,

        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
        private readonly User $createdByUser,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function setApproved(bool $approved): void
    {
        $this->approved = $approved;
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    public function getTag(): Tag
    {
        return $this->tag;
    }

    public function getCreatedByUser(): User
    {
        return $this->createdByUser;
    }

    // EasyAdmin renders association selects through this.
    public function __toString(): string
    {
        return $this->tag.' ('.$this->createdByUser.' @ '.$this->node.')';
    }
}
