<?php

namespace App\Repository;

use App\Entity\RoutePreferenceTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method RoutePreferenceTag|null find($id, $lockMode = null, $lockVersion = null)
 * @method RoutePreferenceTag|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<RoutePreferenceTag> findAll()
 * @method list<RoutePreferenceTag> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class RoutePreferenceTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoutePreferenceTag::class);
    }

    /**
     * The tag keys exposed as route preference settings, sorted for stable
     * display in the admin list and the profile page.
     *
     * @return list<string>
     */
    public function findAllTagKeys(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.tag')
            ->orderBy('t.tag', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('strval', $rows);
    }

    /**
     * The tag keys the admin mapped to an OSM road class, as a
     * tag => road class map. Tags without a mapping have no entry.
     *
     * @return array<string, string>
     */
    public function findOsmRoadClassesByTag(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.tag', 't.osmRoadClass')
            ->andWhere('t.osmRoadClass IS NOT NULL')
            ->orderBy('t.tag', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $roadClasses = [];
        foreach ($rows as $row) {
            $roadClasses[(string) $row['tag']] = (string) $row['osmRoadClass'];
        }

        return $roadClasses;
    }
}
