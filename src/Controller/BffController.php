<?php

namespace App\Controller;

use App\Entity\Product;
use App\Message\ImportProductsMessage;
use App\Repository\ProductRepository;
use App\Service\FeedReader;
use App\Service\PriceAdjuster;
use App\Service\ProductImporter;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Backend-for-frontend: same-origin JSON consumed by the Vue SPA.
 *
 * These endpoints are intentionally NOT behind the X-API-Key check (that key
 * would otherwise have to be shipped to the browser). The public, key-protected
 * surface for external consumers remains the /api/* API. In a real deployment
 * the BFF would sit behind user authentication.
 */
#[Route('/app-api', name: 'bff_')]
class BffController
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly PriceAdjuster $priceAdjuster,
        private readonly FeedReader $feedReader,
        private readonly ProductImporter $importer,
        private readonly MessageBusInterface $bus,
        private readonly Security $security,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->security->getUser();

        return new JsonResponse([
            'username' => $user?->getUserIdentifier(),
            'roles' => $user?->getRoles() ?? [],
        ]);
    }

    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $summary = $this->productRepository->getSummary();
        if ([] === $summary['currencies']) {
            $summary['currencies'] = new stdClass();
        }

        return new JsonResponse($summary);
    }

    #[Route('/products', name: 'products', methods: ['GET'])]
    public function products(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('limit', 20)));

        $currency = trim((string) $request->query->get('currency', ''));
        $min = $request->query->get('min_price');
        $max = $request->query->get('max_price');

        $filters = [
            'currency' => '' !== $currency ? $currency : null,
            'min_price' => is_numeric($min) ? (float) $min : null,
            'max_price' => is_numeric($max) ? (float) $max : null,
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
        ]);
    }

    #[Route('/duplicates', name: 'duplicates', methods: ['GET'])]
    public function duplicates(): JsonResponse
    {
        $duplicates = $this->productRepository->findDuplicates();

        return new JsonResponse([
            'count' => \count($duplicates),
            'data' => array_map(static fn (Product $p) => $p->toArray(), $duplicates),
        ]);
    }

    #[Route('/adjust-prices', name: 'adjust', methods: ['POST'])]
    public function adjustPrices(Request $request): JsonResponse
    {
        $raw = $request->getPayload()->get('percentage');
        if (!is_numeric($raw)) {
            return $this->error(\sprintf('Percentage must be a number, got "%s".', (string) $raw));
        }

        try {
            $result = $this->priceAdjuster->adjust((float) $raw);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return new JsonResponse([
            'ok' => true,
            'affected' => $result['affected'],
            'percentage' => $result['percentage'],
            'factor' => $result['factor'],
        ]);
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $upload */
        $upload = $request->files->get('feed');
        $async = filter_var($request->request->get('async', false), \FILTER_VALIDATE_BOOL);

        $path = $this->projectDir.'/products.json';
        $tempToClean = null;

        if ($upload instanceof UploadedFile) {
            if (!$upload->isValid()) {
                return $this->error('File upload failed: '.$upload->getErrorMessage());
            }
            $path = $upload->getPathname();
            $tempToClean = $path;
        }

        try {
            if ($async && !$upload instanceof UploadedFile) {
                $this->bus->dispatch(new ImportProductsMessage($path));

                return new JsonResponse(['ok' => true, 'queued' => true]);
            }

            $records = $this->feedReader->read($path);
            $result = $this->importer->import($records);
        } catch (RuntimeException $e) {
            return $this->error('Import failed: '.$e->getMessage());
        } finally {
            if (null !== $tempToClean && is_file($tempToClean)) {
                @unlink($tempToClean);
            }
        }

        return new JsonResponse([
            'ok' => true,
            'queued' => false,
            'imported' => $result->imported,
            'updated' => $result->updated,
            'failed' => $result->failedCount(),
            'total' => $result->total(),
            'failures' => $result->failed,
        ]);
    }

    private function error(string $message, int $status = JsonResponse::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }
}
