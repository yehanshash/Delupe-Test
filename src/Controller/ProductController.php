<?php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/products', name: 'api_products_')]
class ProductController
{
    public function __construct(
        private readonly ProductRepository $productRepository,
    ) {
    }

    /**
     * GET /api/products?page=1&limit=50&currency=EUR&min_price=100&max_price=500.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 50);
        $limit = max(1, min(100, $limit));

        $filters = [
            'currency' => $this->nullableString($request, 'currency'),
            'min_price' => $this->nullableFloat($request, 'min_price'),
            'max_price' => $this->nullableFloat($request, 'max_price'),
        ];

        $result = $this->productRepository->findByFilters($filters, $page, $limit);
        $total = $result['total'];

        return new JsonResponse([
            'data' => array_map(static fn (Product $p) => $p->toArray(), $result['items']),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
            'filters' => array_filter($filters, static fn ($v) => null !== $v),
        ]);
    }

    /**
     * GET /api/products/summary.
     */
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $summary = $this->productRepository->getSummary();

        // Ensure "currencies" is always a JSON object ({}), never an array ([]).
        if ([] === $summary['currencies']) {
            $summary['currencies'] = new stdClass();
        }

        return new JsonResponse($summary);
    }

    /**
     * GET /api/products/duplicates
     * Products sharing the same name OR the same link.
     */
    #[Route('/duplicates', name: 'duplicates', methods: ['GET'])]
    public function duplicates(): JsonResponse
    {
        $duplicates = $this->productRepository->findDuplicates();

        return new JsonResponse([
            'count' => \count($duplicates),
            'data' => array_map(static fn (Product $p) => $p->toArray(), $duplicates),
        ]);
    }

    private function nullableString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        if (null === $value || '' === $value) {
            return null;
        }

        return (string) $value;
    }

    private function nullableFloat(Request $request, string $key): ?float
    {
        $value = $request->query->get($key);
        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
