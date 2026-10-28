<?php

namespace App\Repository;

use App\Entity\TagKeyLabel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method TagKeyLabel|null find($id, $lockMode = null, $lockVersion = null)
 * @method TagKeyLabel|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<TagKeyLabel> findAll()
 * @method list<TagKeyLabel> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class TagKeyLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TagKeyLabel::class);
    }

    /** @return array<string, string> tag key => label for one locale */
    public function findForLocale(string $locale): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.key, l.label')
            ->where('l.locale = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getArrayResult();

        $labels = [];
        foreach ($rows as $row) {
            $labels[$row['key']] = $row['label'];
        }

        return $labels;
    }

    /**
     * Cheap summary of the table contents used as the HTTP ETag, so clients
     * re-fetch only when a label actually changed.
     */
    public function cacheSignature(): string
    {
        $result = $this->createQueryBuilder('l')
            ->select('COUNT(l.id) AS count, MAX(l.updatedAt) AS newest')
            ->getQuery()
            ->getSingleResult();

        return $result['count'].':'.($result['newest'] instanceof \DateTimeInterface ? $result['newest']->format('c') : '');
    }
}
