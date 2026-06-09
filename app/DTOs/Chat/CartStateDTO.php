<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Cart state echoed back to the chat widget after `get_cart` or `update_cart`.
 *
 * @phpstan-type LineShape array{id:?string,variant_id:string,product_id:?string,title:string,image:?string,quantity:int,line_price_minor_units:?int}
 */
class CartStateDTO extends BaseDTO
{
    /**
     * @param  list<LineShape>  $items
     */
    public function __construct(
        public readonly string $id,
        public readonly int $itemCount,
        public readonly ?int $subtotalMinorUnits,
        public readonly ?string $currency,
        public readonly array $items,
        public readonly ?string $checkoutUrl,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateRequired($this->id, 'cart id');
        $this->validateNonNegative($this->itemCount, 'item_count');

        foreach ($this->items as $line) {
            if (! isset($line['variant_id']) || ! is_string($line['variant_id']) || $line['variant_id'] === '') {
                throw new InvalidArgumentException('Each cart line requires a variant_id.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'item_count' => $this->itemCount,
            'subtotal_minor_units' => $this->subtotalMinorUnits,
            'currency' => $this->currency,
            'items' => $this->items,
            'checkout_url' => $this->checkoutUrl,
        ];
    }
}
