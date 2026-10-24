<?php

namespace App\Repository;

use App\Entity\NodeProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NodeProvider|null find($id, $lockMode = null, $lockVersion = null)
 * @method NodeProvider|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<NodeProvider> findAll()
 * @method list<NodeProvider> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class NodeProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeProvider::class);
    }

    /**
     * Returns the named provider, creating and persisting it on first use.
     */
    public function findOrCreate(string $name): NodeProvider
    {
        $provider = $this->findOneBy(['name' => $name]);

        if ($provider === null) {
            $provider = new NodeProvider();
            $provider->setName($name);
            $this->getEntityManager()->persist($provider);
        }

        return $provider;
    }
}
