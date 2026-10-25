<?php

namespace App\Repository;

use App\Entity\NodeGroup;
use App\Service\StreetPartArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NodeGroup|null find($id, $lockMode = null, $lockVersion = null)
 * @method NodeGroup|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<NodeGroup> findAll()
 * @method list<NodeGroup> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class NodeGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeGroup::class);
    }

    /**
     * @return list<NodeGroup>
     */
    public function findAllOrderedByRecentUpdate(): array
    {
        return $this->findBy([], ['updatedAt' => 'DESC']);
    }

    /**
     * The street part areas of the node groups carrying one of the tags, for
     * the routing custom model (Routing::customModelFor()). The geometries
     * match the ones in NodeGroupResponse::area.
     *
     * @param list<string> $tagKeys
     *
     * @return array<string, list<array{id: int, area: array}>> tag => areas
     *         of the groups carrying it, keyed by group id for deduplication
     */
    public function findAreaGeometriesByTagKeys(array $tagKeys): array
    {
        if ($tagKeys === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT t.key AS tag_key, g.id AS group_id,
                   ST_AsGeoJSON(ST_Buffer(
                       ST_MakeLine(ARRAY(
                           SELECT ST_SetSRID(ST_MakePoint(n.longitude, n.latitude), 4326)
                           FROM node_group_members m
                           JOIN nodes n ON n.id = m.node_id
                           WHERE m.group_id = g.id
                           ORDER BY m.position
                       ))::geography, :radius
                   )) AS area
            FROM node_groups g
            JOIN node_group_tags gts ON gts.group_id = g.id
            JOIN tags t ON t.id = gts.tag_id
            WHERE t.key IN (:tagKeys)
            ORDER BY g.id
            SQL,
            ['tagKeys' => $tagKeys, 'radius' => StreetPartArea::AREA_RADIUS_METERS],
            ['tagKeys' => ArrayParameterType::STRING],
        );

        $areas = [];
        foreach ($rows as $row) {
            $geometry = json_decode((string) $row['area'], true);
            if (!\is_array($geometry)) {
                // Degenerate member sets cannot form a corridor.
                continue;
            }
            $areas[$row['tag_key']][(int) $row['group_id']] = [
                'id' => (int) $row['group_id'],
                'area' => $geometry,
            ];
        }

        return array_map(static fn (array $list): array => array_values($list), $areas);
    }
}
