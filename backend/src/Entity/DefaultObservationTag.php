<?php

namespace App\Entity;

use App\Repository\DefaultObservationTagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tag key an admin exposes as a default observation tag, e.g. the OSM key
 * "wheelchair" or a custom one like "step_count", together with the values
 * users can pick for it. The frontend offers these keys as ready-made
 * suggestions when users add observations to a node or node relation.
 */
#[ORM\Entity(repositoryClass: DefaultObservationTagRepository::class)]
#[ORM\Table(name: 'default_observation_tags')]
#[ORM\UniqueConstraint(name: 'uq_default_observation_tags_tag', columns: ['tag'])]
class DefaultObservationTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $tag = '';

    /**
     * The values users can pick for this tag when adding an observation,
     * in admin-defined order; an empty list leaves the value free-form.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $defaultValues = [];

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

    /**
     * @return list<string>
     */
    public function getDefaultValues(): array
    {
        return $this->defaultValues;
    }

    /**
     * @param list<string> $defaultValues
     */
    public function setDefaultValues(array $defaultValues): void
    {
        $this->defaultValues = $defaultValues;
    }

    // EasyAdmin renders entity selects through this.
    public function __toString(): string
    {
        return $this->tag;
    }
}
