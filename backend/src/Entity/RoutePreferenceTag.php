<?php

namespace App\Entity;

use App\Repository\RoutePreferenceTagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tag key an admin exposes as a route preference setting, e.g. "stairs"
 * or "toilets". Users then pick a priority level per tag on the profile
 * page; the levels apply to app nodes tagged with the key.
 */
#[ORM\Entity(repositoryClass: RoutePreferenceTagRepository::class)]
#[ORM\Table(name: 'route_preference_tags')]
#[ORM\UniqueConstraint(name: 'uq_route_preference_tags_tag', columns: ['tag'])]
class RoutePreferenceTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $tag = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }

    // EasyAdmin renders entity selects through this.
    public function __toString(): string
    {
        return $this->tag;
    }
}
