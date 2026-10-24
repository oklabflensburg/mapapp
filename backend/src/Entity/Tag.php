<?php

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(name: 'uq_tags_key_value', columns: ['key', 'value'])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'key', length: 255)]
    private string $key = '';

    #[ORM\Column(length: 255)]
    private string $value = '';

    #[ORM\Column]
    private bool $approved = false;

    /** @var Collection<int, NodeTag> */
    #[ORM\OneToMany(mappedBy: 'tag', targetEntity: NodeTag::class)]
    private Collection $nodeTags;

    public function __construct()
    {
        $this->nodeTags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function setApproved(bool $approved): void
    {
        $this->approved = $approved;
    }

    /** @return Collection<int, NodeTag> */
    public function getNodeTags(): Collection
    {
        return $this->nodeTags;
    }

    // EasyAdmin renders association selects through this.
    public function __toString(): string
    {
        return $this->key.'='.$this->value;
    }
}
