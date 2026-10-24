<?php

namespace App\Entity;

use App\Repository\NodeTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NodeTypeRepository::class)]
#[ORM\Table(name: 'node_types')]
#[ORM\UniqueConstraint(name: 'uq_node_types_name', columns: ['name'])]
class NodeType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    // EasyAdmin renders association selects through this.
    public function __toString(): string
    {
        return $this->name;
    }
}
