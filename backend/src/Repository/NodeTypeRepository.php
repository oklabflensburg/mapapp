<?php

namespace App\Repository;

use App\Entity\NodeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NodeType|null find($id, $lockMode = null, $lockVersion = null)
 * @method NodeType|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<NodeType> findAll()
 * @method list<NodeType> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class NodeTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeType::class);
    }

    /**
     * Returns the named type, creating and persisting it on first use.
     */
    public function findOrCreate(string $name): NodeType
    {
        $type = $this->findOneBy(['name' => $name]);

        if ($type === null) {
            $type = new NodeType();
            $type->setName($name);
            $this->getEntityManager()->persist($type);
        }

        return $type;
    }
}
