<?php

namespace App\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Applies a percentage price change to every product, preserving the original
 * price (captured only once, when not already set). Shared by the CLI command
 * and the web dashboard.
 */
class PriceAdjuster
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly LoggerInterface $importLogger,
    ) {
    }

    /**
     * @return array{affected: int, factor: float, percentage: float}
     *
     * @throws InvalidArgumentException when the percentage would zero/invert prices
     */
    public function adjust(float $percentage): array
    {
        if ($percentage <= -100.0) {
            throw new InvalidArgumentException('Percentage must be greater than -100 (a -100% or lower change would zero out or invert prices).');
        }

        $factor = 1 + ($percentage / 100);
        $now = new DateTimeImmutable();

        $count = (int) $this->productRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if (0 === $count) {
            return ['affected' => 0, 'factor' => $factor, 'percentage' => $percentage];
        }

        $this->importLogger->info('Price adjustment started', [
            'percentage' => $percentage,
            'factor' => $factor,
            'products' => $count,
        ]);

        $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $em) use ($factor, $now): void {
            // 1) Preserve the original price for rows that don't have one yet.
            $em->createQuery(
                'UPDATE '.Product::class.' p
                 SET p.originalPrice = p.price, p.updatedAt = :now
                 WHERE p.originalPrice IS NULL'
            )->setParameter('now', $now)->execute();

            // 2) Apply the percentage change to every price.
            $em->createQuery(
                'UPDATE '.Product::class.' p
                 SET p.price = p.price * :factor, p.updatedAt = :now'
            )
                ->setParameter('factor', $factor)
                ->setParameter('now', $now)
                ->execute();
        });

        $this->importLogger->info('Price adjustment completed', [
            'percentage' => $percentage,
            'affected' => $count,
        ]);

        return ['affected' => $count, 'factor' => $factor, 'percentage' => $percentage];
    }
}
