<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\UniqueConstraint(name: 'uniq_merchant_external', columns: ['merchant_id', 'external_id'])]
#[ORM\Index(name: 'idx_products_currency', columns: ['currency'])]
#[ORM\Index(name: 'idx_products_price', columns: ['price'])]
#[ORM\Index(name: 'idx_products_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identifier supplied by the merchant in the feed (the "product id").
     * Combined with the merchant id it forms the natural key used for upserts.
     */
    #[ORM\Column(name: 'external_id', length: 191)]
    private string $externalId;

    #[ORM\Column(name: 'merchant_id', length: 191)]
    private string $merchantId;

    #[ORM\Column(length: 500)]
    private string $name;

    #[ORM\Column(length: 1000)]
    private string $link;

    #[ORM\Column(name: 'image_link', length: 1000, nullable: true)]
    private ?string $imageLink = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $price;

    #[ORM\Column(name: 'original_price', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $originalPrice = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new DateTimeImmutable();
        // Preserve any explicitly set createdAt, otherwise stamp now.
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function setMerchantId(string $merchantId): self
    {
        $this->merchantId = $merchantId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function setLink(string $link): self
    {
        $this->link = $link;

        return $this;
    }

    public function getImageLink(): ?string
    {
        return $this->imageLink;
    }

    public function setImageLink(?string $imageLink): self
    {
        $this->imageLink = $imageLink;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getPriceAsFloat(): float
    {
        return (float) $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getOriginalPrice(): ?string
    {
        return $this->originalPrice;
    }

    public function getOriginalPriceAsFloat(): ?float
    {
        return null === $this->originalPrice ? null : (float) $this->originalPrice;
    }

    public function setOriginalPrice(?string $originalPrice): self
    {
        $this->originalPrice = $originalPrice;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->externalId,
            'merchant_id' => $this->merchantId,
            'name' => $this->name,
            'link' => $this->link,
            'image_link' => $this->imageLink,
            'price' => $this->getPriceAsFloat(),
            'original_price' => $this->getOriginalPriceAsFloat(),
            'currency' => $this->currency,
            'created_at' => $this->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
