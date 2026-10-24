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
}
