<?php

namespace App\Tests\Controller;

use App\Entity\Product;
use App\Tests\RecreatesSchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the JSON API, including the summary endpoint and
 * the API-key security layer.
 */
final class ProductApiControllerTest extends WebTestCase
{
    use RecreatesSchemaTrait;

    private const API_KEY_HEADER = ['HTTP_X-API-KEY' => 'test_api_key'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->recreateSchema(static::getContainer());
        $this->seedProducts();
    }

    private function seedProducts(): void
    {
        $rows = [
            ['EUR-1', 'EUR', 100.00],
            ['EUR-2', 'EUR', 200.00],
            ['USD-1', 'USD', 300.00],
        ];

        foreach ($rows as [$id, $currency, $price]) {
            $product = new Product();
            $product->setExternalId($id)
                ->setMerchantId('m-1')
                ->setName('Product '.$id)
                ->setLink('https://example.com/p/'.$id)
                ->setImageLink('https://example.com/img/'.$id.'.jpg')
                ->setPrice(number_format($price, 2, '.', ''))
                ->setCurrency($currency);
            $this->em->persist($product);
        }

        $this->em->flush();
        $this->em->clear();
    }

    public function testSummaryReturnsAggregatedReport(): void
    {
        $this->client->request('GET', '/api/products/summary', server: self::API_KEY_HEADER);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(3, $data['count']);
        // JSON encodes 600.0 as 600, so compare loosely on the numeric value.
        self::assertEquals(600.0, $data['total_price']);
        self::assertEquals(200.0, $data['average_price']);
        self::assertSame(['EUR' => 2, 'USD' => 1], $data['currencies']);
    }

    public function testSummaryRequiresApiKey(): void
    {
        $this->client->request('GET', '/api/products/summary');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSummaryRejectsWrongApiKey(): void
    {
        $this->client->request('GET', '/api/products/summary', server: ['HTTP_X-API-KEY' => 'wrong']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testProductListSupportsCurrencyAndPriceFilters(): void
    {
        $this->client->request('GET', '/api/products?currency=EUR&min_price=150', server: self::API_KEY_HEADER);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(1, $data['data']);
        self::assertSame('EUR-2', $data['data'][0]['external_id']);
        self::assertSame(1, $data['pagination']['total']);
    }

    public function testProductListPaginationMetadata(): void
    {
        $this->client->request('GET', '/api/products?page=1&limit=2', server: self::API_KEY_HEADER);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(2, $data['data']);
        self::assertSame(3, $data['pagination']['total']);
        self::assertSame(2, $data['pagination']['pages']);
    }

    public function testHealthEndpointIsPublicAndReportsDatabase(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('ok', $data['status']);
        self::assertSame('connected', $data['database']);
    }
}
