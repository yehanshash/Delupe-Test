<?php

namespace App\Tests\Validation;

use App\Dto\ProductInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Pure unit tests for the import validation rules (no kernel / database).
 */
final class ProductInputValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * @param array<string, mixed> $record
     */
    private function violationMessages(array $record): array
    {
        $violations = $this->validator->validate(ProductInput::fromArray($record));
        $messages = [];
        foreach ($violations as $v) {
            $messages[] = (string) $v->getMessage();
        }

        return $messages;
    }

    public function testValidRecordHasNoViolations(): void
    {
        $messages = $this->violationMessages([
            'id' => 'SKU-1',
            'merchant_id' => 'm-1',
            'name' => 'Valid Product',
            'link' => 'https://example.com/p/1',
            'price' => 19.99,
            'currency' => 'EUR',
        ]);

        self::assertSame([], $messages);
    }

    public function testEmptyNameIsRejected(): void
    {
        $messages = $this->violationMessages([
            'id' => 'SKU-2',
            'merchant_id' => 'm-1',
            'name' => '',
            'link' => 'https://example.com/p/2',
            'price' => 10,
            'currency' => 'EUR',
        ]);

        self::assertContains('Product name cannot be empty.', $messages);
    }

    public function testZeroPriceIsRejected(): void
    {
        $messages = $this->violationMessages([
            'id' => 'SKU-3',
            'merchant_id' => 'm-1',
            'name' => 'Free Thing',
            'link' => 'https://example.com/p/3',
            'price' => 0,
            'currency' => 'EUR',
        ]);

        self::assertContains('Price must be greater than zero.', $messages);
    }

    public function testNegativePriceIsRejected(): void
    {
        $messages = $this->violationMessages([
            'id' => 'SKU-3b',
            'merchant_id' => 'm-1',
            'name' => 'Negative Thing',
            'link' => 'https://example.com/p/3b',
            'price' => -5,
            'currency' => 'EUR',
        ]);

        self::assertContains('Price must be greater than zero.', $messages);
    }

    public function testInvalidCurrencyIsRejected(): void
    {
        $messages = $this->violationMessages([
            'id' => 'SKU-4',
            'merchant_id' => 'm-1',
            'name' => 'Mystery',
            'link' => 'https://example.com/p/4',
            'price' => 9.99,
            'currency' => 'XX',
        ]);

        self::assertContains('Currency must be a valid ISO 4217 code (e.g. EUR, USD, GBP).', $messages);
    }

    public function testStringPriceWithCurrencySuffixIsParsed(): void
    {
        $input = ProductInput::fromArray([
            'id' => 'SKU-5',
            'merchant_id' => 'm-1',
            'name' => 'Webcam',
            'link' => 'https://example.com/p/5',
            'price' => '75.25 USD',
            'currency' => 'USD',
        ]);

        self::assertSame(75.25, $input->price);
        self::assertCount(0, $this->validator->validate($input));
    }

    public function testGoogleStyleAliasesAreMapped(): void
    {
        $input = ProductInput::fromArray([
            'id' => 'SKU-6',
            'merchant' => 'm-9',
            'title' => 'Aliased Product',
            'url' => 'https://example.com/p/6',
            'sale_price' => 12.34,
            'currency_code' => 'gbp',
        ]);

        self::assertSame('SKU-6', $input->externalId);
        self::assertSame('m-9', $input->merchantId);
        self::assertSame('Aliased Product', $input->name);
        self::assertSame('https://example.com/p/6', $input->link);
        self::assertSame(12.34, $input->price);
        self::assertSame('GBP', $input->currency);
        self::assertCount(0, $this->validator->validate($input));
    }
}
