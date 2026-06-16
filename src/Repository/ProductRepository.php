<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findOneByNaturalKey(string $merchantId, string $externalId): ?Product
    {
        return $this->findOneBy([
            'merchantId' => $merchantId,
            'externalId' => $externalId,
        ]);
    }

    /**
     * Paginated, filtered product listing.
     *
     * @param array{currency?: ?string, min_price?: ?float, max_price?: ?float} $filters
     *
     * @return array{items: list<Product>, total: int}
     */
    public function findByFilters(array $filters, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        $qb = $this->createQueryBuilder('p');

        if (!empty($filters['currency'])) {
            $qb->andWhere('p.currency = :currency')
                ->setParameter('currency', strtoupper((string) $filters['currency']));
        }

        if (isset($filters['min_price']) && null !== $filters['min_price']) {
            $qb->andWhere('p.price >= :minPrice')
                ->setParameter('minPrice', $filters['min_price']);
        }

        if (isset($filters['max_price']) && null !== $filters['max_price']) {
            $qb->andWhere('p.price <= :maxPrice')
                ->setParameter('maxPrice', $filters['max_price']);
        }

        $qb->orderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);

        return [
            'items' => iterator_to_array($paginator),
            'total' => \count($paginator),
        ];
    }

    /**
     * Aggregate report across all products.
     *
     * @return array{count: int, total_price: float, average_price: float, currencies: array<string, int>}
     */
    public function getSummary(): array
    {
        /** @var array{cnt: string|int|null, total: string|float|null} $agg */
        $agg = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) AS cnt', 'COALESCE(SUM(p.price), 0) AS total')
            ->getQuery()
            ->getSingleResult();

        $count = (int) ($agg['cnt'] ?? 0);
        $total = (float) ($agg['total'] ?? 0);

        /** @var list<array{currency: string, cnt: string|int}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.currency AS currency', 'COUNT(p.id) AS cnt')
            ->groupBy('p.currency')
            ->orderBy('p.currency', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $currencies = [];
        foreach ($rows as $row) {
            $currencies[$row['currency']] = (int) $row['cnt'];
        }

        return [
            'count' => $count,
            'total_price' => round($total, 2),
            'average_price' => $count > 0 ? round($total / $count, 2) : 0.0,
            'currencies' => $currencies,
        ];
    }

    /**
     * Products that share the same name OR the same link with at least one other product.
     *
     * @return list<Product>
     */
    public function findDuplicates(): array
    {
        $dql = <<<'DQL'
            SELECT p FROM App\Entity\Product p
            WHERE p.name IN (
                SELECT pn.name FROM App\Entity\Product pn
                GROUP BY pn.name HAVING COUNT(pn.id) > 1
            )
            OR p.link IN (
                SELECT pl.link FROM App\Entity\Product pl
                GROUP BY pl.link HAVING COUNT(pl.id) > 1
            )
            ORDER BY p.name ASC, p.link ASC, p.id ASC
            DQL;

        /** @var list<Product> $result */
        $result = $this->getEntityManager()->createQuery($dql)->getResult();

        return $result;
    }
}
