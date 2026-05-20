<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Snapshot of the visitor's current Shopify cart at the moment of the message.
 * Comes from /cart.js on the storefront.
 */
class CartContextDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $id,
        public readonly int $itemCount,
        public readonly ?string $totalPrice,
        public readonly ?string $currency,
        public readonly array $items,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateNonNegative($this->itemCount, 'Cart item count');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            itemCount: (int) ($data['item_count'] ?? $data['itemCount'] ?? 0),
            totalPrice: isset($data['total_price']) ? (string) $data['total_price'] : (isset($data['totalPrice']) ? (string) $data['totalPrice'] : null),
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            items: array_values((array) ($data['items'] ?? [])),
        );
    }

    public function isEmpty(): bool
    {
        return $this->itemCount === 0;
    }
}
