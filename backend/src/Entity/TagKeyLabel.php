<?php

namespace App\Entity;

use App\Repository\TagKeyLabelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A human-readable label for a tag key (e.g. "wheelchair" →
 * "Rollstuhlzugänglichkeit"), set by admins in EasyAdmin. It overrides the
 * tag_keys translation catalogue for its locale; anything without a row
 * there falls back to the catalogue or the prettified key.
 */
#[ORM\Entity(repositoryClass: TagKeyLabelRepository::class)]
#[ORM\Table(name: 'tag_key_labels')]
#[ORM\UniqueConstraint(name: 'uq_tag_key_labels_key_locale', columns: ['key', 'locale'])]
class TagKeyLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $key = '';

    /** @see TagKeyLabeler::SUPPORTED_LOCALES */
    #[ORM\Column(length: 5)]
    private string $locale = '';

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // Labels feed the /api/i18n/tag-keys cache signature, so any edit must
    // bump the timestamp.
    #[ORM\PreUpdate]
    public function bumpUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->key.' ('.$this->locale.')';
    }
}
