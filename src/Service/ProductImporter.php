<?php

namespace App\Service;

use App\Dto\ProductInput;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Core import logic: validate raw records and upsert them into PostgreSQL.
 *
 * Records are matched on the natural key (merchant_id + external/product id):
 * a matching product is updated, otherwise a new one is inserted.
 */
class ProductImporter
{
    private const FLUSH_EVERY = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $importLogger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public function import(array $records): ImportResult
    {
        $result = new ImportResult();
        $total = \count($records);

        $this->importLogger->info('Import started', ['records' => $total]);

        /** @var array<string, Product> $seen in-run cache to dedupe repeated keys within one feed */
        $seen = [];

        foreach ($records as $index => $record) {
            if (!\is_array($record)) {
                $result->addFailure($index, '', ['Record is not a JSON object.']);
                $this->importLogger->warning('Validation error', [
                    'index' => $index,
                    'errors' => ['Record is not a JSON object.'],
                ]);
                continue;
            }

            $input = ProductInput::fromArray($record);
            $violations = $this->validator->validate($input);

            if ($violations->count() > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[] = \sprintf('%s: %s', $violation->getPropertyPath(), (string) $violation->getMessage());
                }
                $result->addFailure($index, $input->externalId, $errors);
                $this->importLogger->warning('Validation error', [
                    'index' => $index,
                    'external_id' => $input->externalId,
                    'errors' => $errors,
                ]);
                continue;
            }

            $key = $input->merchantId.'|'.$input->externalId;

            $product = $seen[$key]
                ?? $this->productRepository->findOneByNaturalKey($input->merchantId, $input->externalId);

            if (null === $product) {
                $product = new Product();
                $product->setMerchantId($input->merchantId);
                $product->setExternalId($input->externalId);
                $this->applyInput($product, $input);
                $this->entityManager->persist($product);
                $result->addImported();
            } else {
                $this->applyInput($product, $input);
                $result->addUpdated();
            }

            $seen[$key] = $product;

            if (0 === (($index + 1) % self::FLUSH_EVERY)) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $this->importLogger->info('Import completed', [
            'imported' => $result->imported,
            'updated' => $result->updated,
            'failed' => $result->failedCount(),
            'total' => $total,
        ]);
        $this->importLogger->info('Number of successful records', [
            'successful' => $result->imported + $result->updated,
        ]);
        $this->importLogger->info('Number of failed records', [
            'failed' => $result->failedCount(),
        ]);

        return $result;
    }

    private function applyInput(Product $product, ProductInput $input): void
    {
        $product->setName($input->name);
        $product->setLink($input->link);
        $product->setImageLink($input->imageLink);
        $product->setPrice(self::money($input->price));
        $product->setOriginalPrice(null === $input->originalPrice ? null : self::money($input->originalPrice));
        $product->setCurrency($input->currency);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
