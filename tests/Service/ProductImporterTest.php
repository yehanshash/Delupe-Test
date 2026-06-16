<?php

namespace App\Tests\Service;

use App\Repository\ProductRepository;
use App\Service\ImportResult;
use App\Service\ProductImporter;
use App\Tests\RecreatesSchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for the import pipeline (requires the test database).
 */
final class ProductImporterTest extends KernelTestCase
{
    use RecreatesSchemaTrait;

    private EntityManagerInterface $em;
    private ProductImporter $importer;
    private ProductRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $this->recreateSchema($container);
        $this->importer = $container->get(ProductImporter::class);
        $this->repository = $container->get(ProductRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $id, string $name = 'Product', float $price = 10.0, string $currency = 'EUR', string $merchant = 'm-1'): array
    {
        return [
            'id' => $id,
            'merchant_id' => $merchant,
            'name' => $name,
            'link' => 'https://example.com/p/'.$id,
            'image_link' => 'https://example.com/img/'.$id.'.jpg',
            'price' => $price,
            'currency' => $currency,
        ];
    }

    public function testImportsNewProducts(): void
    {
        $result = $this->importer->import([
            $this->record('A', 'Alpha', 10.0),
            $this->record('B', 'Beta', 20.0),
        ]);

        self::assertInstanceOf(ImportResult::class, $result);
        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->failedCount());
        self::assertSame(2, $this->repository->count([]));
    }

    public function testInvalidRecordsAreReportedAsFailed(): void
    {
        $result = $this->importer->import([
            $this->record('A', 'Alpha', 10.0),
            $this->record('B', '', 10.0),        // empty name
            $this->record('C', 'Gamma', 0.0),    // zero price
            $this->record('D', 'Delta', 5.0, 'XX'), // bad currency
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(3, $result->failedCount());
        self::assertSame(1, $this->repository->count([]));

        // Each failure carries its record index and at least one error message.
        foreach ($result->failed as $failure) {
            self::assertArrayHasKey('index', $failure);
            self::assertNotEmpty($failure['errors']);
        }
    }

    public function testReimportUpdatesExistingProduct(): void
    {
        $this->importer->import([$this->record('A', 'Alpha', 10.0)]);

        $result = $this->importer->import([$this->record('A', 'Alpha Renamed', 25.5)]);

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $this->repository->count([]));

        $product = $this->repository->findOneByNaturalKey('m-1', 'A');
        self::assertNotNull($product);
        self::assertSame('Alpha Renamed', $product->getName());
        self::assertSame('25.50', $product->getPrice());
    }

    public function testDuplicateKeyWithinSameFeedIsUpserted(): void
    {
        $result = $this->importer->import([
            $this->record('A', 'First', 10.0),
            $this->record('A', 'Second', 20.0), // same merchant + id
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $this->repository->count([]));

        $product = $this->repository->findOneByNaturalKey('m-1', 'A');
        self::assertNotNull($product);
        self::assertSame('Second', $product->getName());
    }
}
