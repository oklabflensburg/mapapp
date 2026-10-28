<?php

namespace App\Repository;

use App\Entity\DefaultObservationTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method DefaultObservationTag|null find($id, $lockMode = null, $lockVersion = null)
 * @method DefaultObservationTag|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<DefaultObservationTag> findAll()
 * @method list<DefaultObservationTag> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class DefaultObservationTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DefaultObservationTag::class);
    }

    /**
     * The tag keys exposed as default observation tags with their
     * selectable values, sorted for stable display in the admin list and
     * the frontend suggestions.
     *
     * @return list<array{tag: string, values: list<string>}>
     */
    public function findAllWithValues(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.tag', 't.defaultValues')
            ->orderBy('t.tag', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $tags = [];
        foreach ($rows as $row) {
            $tags[] = [
                'tag' => (string) $row['tag'],
                'values' => array_map('strval', $row['defaultValues'] ?? []),
            ];
        }

        return $tags;
    }
}
