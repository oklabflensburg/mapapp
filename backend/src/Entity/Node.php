<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nodes')]
#[ORM\UniqueConstraint(name: 'uq_nodes_provider_node', columns: ['node_provider_id', 'provider_node_id'])]
class Node
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NodeType::class)]
    #[ORM\JoinColumn(name: 'node_type_id', nullable: false)]
    private NodeType $nodeType;

    #[ORM\ManyToOne(targetEntity: NodeProvider::class)]
    #[ORM\JoinColumn(name: 'node_provider_id', nullable: false)]
    private NodeProvider $nodeProvider;

    /** Id of the original object at the provider, e.g. "n26013095" for an OSM node. */
    #[ORM\Column(name: 'provider_node_id', length: 64)]
    private string $providerNodeId = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
    private User $createdByUser;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private float $latitude = 0.0;

    #[ORM\Column]
    private float $longitude = 0.0;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'nodes_tags')]
    private Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNodeType(): NodeType
    {
        return $this->nodeType;
    }

    public function setNodeType(NodeType $nodeType): void
    {
        $this->nodeType = $nodeType;
    }

    public function getNodeProvider(): NodeProvider
    {
        return $this->nodeProvider;
    }

    public function setNodeProvider(NodeProvider $nodeProvider): void
    {
        $this->nodeProvider = $nodeProvider;
    }

    public function getProviderNodeId(): string
    {
        return $this->providerNodeId;
    }

    public function setProviderNodeId(string $providerNodeId): void
    {
        $this->providerNodeId = $providerNodeId;
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

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function setLatitude(float $latitude): void
    {
        $this->latitude = $latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function setLongitude(float $longitude): void
    {
        $this->longitude = $longitude;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /** @param list<Tag> $tags */
    public function setTags(array $tags): void
    {
        $this->tags = new ArrayCollection($tags);
    }
}
