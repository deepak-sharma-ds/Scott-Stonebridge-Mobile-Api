<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

use App\DTOs\BaseDTO;
use Illuminate\Support\Collection;

final readonly class CartDTO extends BaseDTO
{
    public function __construct(
        public string $id,
        public string $checkoutUrl,
        public Collection $lines,           // Collection<CartLineItemDTO>
        public MoneyDTO $totalAmount,
        public MoneyDTO $subtotalAmount,
        public ?MoneyDTO $totalTaxAmount,
        public ?string $discountCode,
        public ?MoneyDTO $discountAmount,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
    
    /**
     * Create from Shopify cart response
     */
    public static function fromShopifyCart(array $cart): self
    {
        return new self(
            id: $cart['id'],
            checkoutUrl: $cart['checkoutUrl'],
            lines: collect($cart['lines']['edges'] ?? [])->map(
                fn($edge) => CartLineItemDTO::fromShopifyNode($edge['node'])
            ),
            totalAmount: MoneyDTO::fromShopifyMoney($cart['cost']['totalAmount']),
            subtotalAmount: MoneyDTO::fromShopifyMoney($cart['cost']['subtotalAmount']),
            totalTaxAmount: isset($cart['cost']['totalTaxAmount']) 
                ? MoneyDTO::fromShopifyMoney($cart['cost']['totalTaxAmount']) 
                : null,
            discountCode: $cart['discountCodes'][0]['code'] ?? null,
            discountAmount: isset($cart['cost']['totalDutyAmount'])
                ? MoneyDTO::fromShopifyMoney($cart['cost']['totalDutyAmount'])
                : null,
            createdAt: new \DateTimeImmutable($cart['createdAt']),
            updatedAt: new \DateTimeImmutable($cart['updatedAt']),
        );
    }
}
