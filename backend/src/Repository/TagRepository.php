<?php

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Tag|null find($id, $lockMode = null, $lockVersion = null)
 * @method Tag|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<Tag> findAll()
 * @method list<Tag> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * Returns the tag with this key and value, creating and persisting it on
     * first use. $approved only applies to newly created tags; existing ones
     * keep their approval state.
     */
    public function findOrCreate(string $key, string $value, bool $approved = false): Tag
    {
        $tag = $this->findOneBy(['key' => $key, 'value' => $value]);

        if ($tag === null) {
            $tag = new Tag();
            $tag->setKey($key);
            $tag->setValue($value);
            $tag->setApproved($approved);
            $this->getEntityManager()->persist($tag);
        }

        return $tag;
    }
}
