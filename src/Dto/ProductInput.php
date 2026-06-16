<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated representation of a single product record from an import feed.
 *
 * Accepts the common Google-style feed keys (id, title, sale_price, ...) as
 * well as the canonical names used by this application.
 */
class ProductInput
{
    #[Assert\NotBlank(message: 'Product id (external id) cannot be empty.')]
    #[Assert\Length(max: 191)]
    public string $externalId = '';

    #[Assert\NotBlank(message: 'Merchant id cannot be empty.')]
    #[Assert\Length(max: 191)]
    public string $merchantId = '';

    #[Assert\NotBlank(message: 'Product name cannot be empty.')]
    #[Assert\Length(max: 500)]
    public string $name = '';

    #[Assert\NotBlank(message: 'Product link cannot be empty.')]
    #[Assert\Length(max: 1000)]
    public string $link = '';

    #[Assert\Length(max: 1000)]
    public ?string $imageLink = null;

    #[Assert\NotNull(message: 'Price is required.')]
    #[Assert\GreaterThan(value: 0, message: 'Price must be greater than zero.')]
    public float $price = 0.0;

    #[Assert\GreaterThanOrEqual(value: 0, message: 'Original price cannot be negative.')]
    public ?float $originalPrice = null;

    #[Assert\NotBlank(message: 'Currency cannot be empty.')]
    #[Assert\Currency(message: 'Currency must be a valid ISO 4217 code (e.g. EUR, USD, GBP).')]
    public string $currency = '';

    /**
     * Build a DTO from a raw decoded JSON record, tolerating common key aliases.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();

        $dto->externalId = self::str($data, ['external_id', 'id', 'product_id', 'sku']);
        $dto->merchantId = self::str($data, ['merchant_id', 'merchantId', 'merchant']);
        $dto->name = self::str($data, ['name', 'title']);
        $dto->link = self::str($data, ['link', 'url']);

        $imageLink = self::strOrNull($data, ['image_link', 'imageLink', 'image']);
        $dto->imageLink = $imageLink;

        $dto->price = self::price($data, ['price', 'sale_price']);
        $dto->originalPrice = self::priceOrNull($data, ['original_price', 'originalPrice', 'list_price']);

        $dto->currency = strtoupper(self::str($data, ['currency', 'currency_code']));

        return $dto;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private static function str(array $data, array $keys): string
    {
        $value = self::first($data, $keys);

        return null === $value ? '' : trim((string) $value);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private static function strOrNull(array $data, array $keys): ?string
    {
        $value = self::first($data, $keys);
        if (null === $value) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private static function price(array $data, array $keys): float
    {
        $value = self::first($data, $keys);

        return self::normalizePrice($value) ?? 0.0;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private static function priceOrNull(array $data, array $keys): ?float
    {
        $value = self::first($data, $keys);

        return self::normalizePrice($value);
    }

    /**
     * Accepts numbers and strings such as "199.99" or "199.99 EUR".
     */
    private static function normalizePrice(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        if (\is_string($value)) {
            // Strip currency suffixes/symbols, keep digits, separators and sign.
            $clean = preg_replace('/[^0-9eE,.\-+]/', '', $value) ?? '';
            $clean = str_replace(',', '.', $clean);
            if (is_numeric($clean)) {
                return (float) $clean;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private static function first(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (\array_key_exists($key, $data) && null !== $data[$key] && '' !== $data[$key]) {
                return $data[$key];
            }
        }

        return null;
    }
}
