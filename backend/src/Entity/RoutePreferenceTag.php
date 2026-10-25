<?php

namespace App\Entity;

use App\Repository\RoutePreferenceTagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tag key an admin exposes as a route preference setting, e.g. "stairs"
 * or "toilets". Users then pick a priority level per tag on the profile
 * page; the levels apply to app nodes tagged with the key. A tag mapped
 * to an OSM road class additionally applies to OSM ways of that class.
 */
#[ORM\Entity(repositoryClass: RoutePreferenceTagRepository::class)]
#[ORM\Table(name: 'route_preference_tags')]
#[ORM\UniqueConstraint(name: 'uq_route_preference_tags_tag', columns: ['tag'])]
class RoutePreferenceTag
{
    /**
     * The road_class values GraphHopper's foot profile can see: its enum
     * minus MOTORWAY and TRUNK, which the OSM import ignores
     * (graphhopper.yml: import.osm.ignored_highways).
     *
     * @see https://github.com/graphhopper/graphhopper/blob/master/core/src/main/java/com/graphhopper/routing/util/RoadClass.java
     */
    public const OSM_ROAD_CLASSES = [
        'PRIMARY', 'SECONDARY', 'TERTIARY', 'RESIDENTIAL', 'UNCLASSIFIED',
        'SERVICE', 'TRACK', 'PATH', 'CYCLEWAY', 'STEPS', 'FERRY',
        'SHUTTLE_TRAIN', 'OTHER',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $tag = '';

    #[ORM\Column(name: 'osm_road_class', length: 30, nullable: true)]
    private ?string $osmRoadClass = null;

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
     * The GraphHopper road_class encoded value OSM ways of this tag carry,
     * e.g. STEPS for "stairs", or null for tags that only apply to app
     * nodes. Only avoid levels can use it: per-request custom models
     * cannot raise edge priority, so OSM ways cannot be preferred.
     */
    public function getOsmRoadClass(): ?string
    {
        return $this->osmRoadClass;
    }

    public function setOsmRoadClass(?string $osmRoadClass): void
    {
        $this->osmRoadClass = $osmRoadClass;
    }

    // EasyAdmin renders entity selects through this.
    public function __toString(): string
    {
        return $this->tag;
    }
}
