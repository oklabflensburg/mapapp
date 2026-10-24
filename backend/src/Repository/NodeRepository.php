<?php

namespace App\Repository;

use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Node|null find($id, $lockMode = null, $lockVersion = null)
 * @method Node|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<Node> findAll()
 * @method list<Node> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class NodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Node::class);
    }

    public function findOneByProvider(string $providerName, string $providerNodeId): ?Node
    {
        return $this->createQueryBuilder('n')
            ->join('n.nodeProvider', 'p')
            ->where('p.name = :provider')
            ->andWhere('n.providerNodeId = :providerNodeId')
            ->setParameter('provider', $providerName)
            ->setParameter('providerNodeId', $providerNodeId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Node>
     */
    public function findAllByNodeType(string $typeName): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.nodeType', 't')
            ->where('t.name = :type')
            ->setParameter('type', $typeName)
            ->orderBy('n.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Coordinates of all nodes carrying one of the given tag keys, grouped by
     * tag key. The same tag can be added to the same node by multiple users;
     * coordinates are deduplicated per tag. Used for routing preferences:
     * a node tagged e.g. "stairs" becomes an area the router can avoid.
     *
     * @param list<string> $tagKeys
     *
     * @return array<string, list<array{latitude: float, longitude: float}>>
     */
    public function findTaggedNodeCoordinates(array $tagKeys): array
    {
        if ($tagKeys === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('n')
            ->select('t.key AS tagKey', 'n.latitude', 'n.longitude')
            ->join('n.nodeTags', 'nt')
            ->join('nt.tag', 't')
            ->where('t.key IN (:tagKeys)')
            ->setParameter('tagKeys', $tagKeys)
            ->getQuery()
            ->getArrayResult();

        $coordinates = [];
        foreach ($rows as $row) {
            $coordinates[$row['tagKey']][$row['latitude'].','.$row['longitude']] = [
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
            ];
        }

        return array_map(static fn (array $points): array => array_values($points), $coordinates);
    }
}
