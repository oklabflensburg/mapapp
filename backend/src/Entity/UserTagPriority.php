<?php

namespace App\Entity;

use App\Repository\UserTagPriorityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A per-user routing priority for a tag (e.g. "stairs" => "avoid"). One row
 * per user and tag; a neutral priority is not stored at all.
 */
#[ORM\Entity(repositoryClass: UserTagPriorityRepository::class)]
#[ORM\Table(name: 'user_tag_priorities')]
#[ORM\UniqueConstraint(name: 'uq_user_tag_priorities_user_tag', columns: ['user_id', 'tag'])]
class UserTagPriority
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private readonly User $user,

        #[ORM\Column(length: 50)]
        private readonly string $tag,

        #[ORM\Column(length: 20)]
        private string $priority,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): void
    {
        $this->priority = $priority;
    }
}
