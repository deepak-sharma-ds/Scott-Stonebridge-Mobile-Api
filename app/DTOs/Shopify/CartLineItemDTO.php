<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

use App\DTOs\BaseDTO;

final readonly class CartLineItemDTO extends BaseDTO
{
    public function __construct(
        public string $id,
        public int $quantity,
        public string $merchandiseId,
        public MoneyDTO $totalAmount,
        public ?array $attributes,
    ) {}
    
    /**
     * Create from Shopify cart line node
     */
    public static function fromShopifyNode(array $line): self
    {
        return new self(
            id: $line['id'],
            quantity: $line['quantity'],
            merchandiseId: $line['merchandise']['id'] ?? '',
            totalAmount: MoneyDTO::fromShopifyMoney($line['cost']['totalAmount']),
            attributes: $line['attributes'] ?? null,
        );
    }
}
