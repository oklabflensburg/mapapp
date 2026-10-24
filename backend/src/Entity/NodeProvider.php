<?php

namespace App\Entity;

use App\Repository\NodeProviderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NodeProviderRepository::class)]
#[ORM\Table(name: 'node_providers')]
#[ORM\UniqueConstraint(name: 'uq_node_providers_name', columns: ['name'])]
class NodeProvider
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
