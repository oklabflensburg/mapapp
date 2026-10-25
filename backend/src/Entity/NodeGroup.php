<?php

namespace App\Entity;

use App\Repository\NodeGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A relation of multiple nodes, e.g. the tagged part of a road. Tags live on
 * the relation and apply to all of its members.
 */
#[ORM\Entity(repositoryClass: NodeGroupRepository::class)]
#[ORM\Table(name: 'node_groups')]
class NodeGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Human-readable name, e.g. of the road part the relation covers. */
    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
    private User $createdByUser;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, NodeGroupMember> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: NodeGroupMember::class, orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $members;

    /** @var Collection<int, NodeGroupTag> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: NodeGroupTag::class, orphanRemoval: true)]
    private Collection $groupTags;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->groupTags = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getCreatedByUser(): User
    {
        return $this->createdByUser;
    }

    public function setCreatedByUser(User $createdByUser): void
    {
        $this->createdByUser = $createdByUser;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, NodeGroupMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * @return Collection<int, NodeGroupTag>
     */
    public function getGroupTags(): Collection
    {
        return $this->groupTags;
    }

    /**
     * Adds the node in click order. Returns null when the node is already a
     * member of this relation.
     */
    public function addMember(Node $node): ?NodeGroupMember
    {
        foreach ($this->members as $member) {
            if ($member->getNode() === $node) {
                return null;
            }
        }

        $member = new NodeGroupMember($this, $node, $this->members->count());
        $this->members->add($member);

        return $member;
    }

    /**
     * Records that the given user added the tag to this relation. Returns
     * null when that user already added the same tag to it.
     */
    public function addTag(Tag $tag, User $user): ?NodeGroupTag
    {
        foreach ($this->groupTags as $groupTag) {
            if ($groupTag->getTag() === $tag && $groupTag->getCreatedByUser() === $user) {
                return null;
            }
        }

        $groupTag = new NodeGroupTag($this, $tag, $user);
        $this->groupTags->add($groupTag);

        return $groupTag;
    }

    // EasyAdmin renders association selects through this.
    public function __toString(): string
    {
        return 'relation #'.($this->id ?? '');
    }
}
