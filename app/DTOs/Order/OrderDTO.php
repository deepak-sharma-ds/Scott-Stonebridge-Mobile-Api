<?php

namespace App\DTOs\Order;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Order Data Transfer Object
 * 
 * Represents a Shopify order with typed properties and validation.
 * Orders contain line items, pricing, and fulfillment information.
 * 
 * Requirements: 16.3, 16.6, 16.7
 */
class OrderDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $orderNumber,
        public readonly string $processedAt,
        public readonly ?string $financialStatus,
        public readonly ?string $fulfillmentStatus,
        public readonly array $totalPrice,
        public readonly array $subtotalPrice,
        public readonly array $totalTax,
        public readonly array $lineItems,
        public readonly ?array $shippingAddress,
    ) {
        $this->validate();
    }

    /**
     * Validate the order data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Order ID');
        $this->validateRequired($this->name, 'Order name');
        $this->validatePositive($this->orderNumber, 'Order number');
    }

    /**
     * Create an OrderDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL order response into a typed DTO instance.
     * Handles nested line items and pricing information.
     * 
     * @param array $data Raw order data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Handle both edge/node structure and flat array structure for line items
        $lineItems = $data['lineItems']['edges'] ?? $data['lineItems'] ?? [];
        
        return new self(
            id: $data['id'],
            name: $data['name'],
            orderNumber: $data['orderNumber'],
            processedAt: $data['processedAt'],
            financialStatus: $data['financialStatus'] ?? null,
            fulfillmentStatus: $data['fulfillmentStatus'] ?? null,
            totalPrice: [
                'amount' => $data['totalPriceV2']['amount'] ?? $data['totalPrice']['amount'] ?? '0.00',
                'currency' => $data['totalPriceV2']['currencyCode'] ?? $data['totalPrice']['currencyCode'] ?? 'GBP',
            ],
            subtotalPrice: [
                'amount' => $data['subtotalPriceV2']['amount'] ?? $data['subtotalPrice']['amount'] ?? '0.00',
                'currency' => $data['subtotalPriceV2']['currencyCode'] ?? $data['subtotalPrice']['currencyCode'] ?? 'GBP',
            ],
            totalTax: [
                'amount' => $data['totalTaxV2']['amount'] ?? $data['totalTax']['amount'] ?? '0.00',
                'currency' => $data['totalTaxV2']['currencyCode'] ?? $data['totalTax']['currencyCode'] ?? 'GBP',
            ],
            lineItems: array_map(
                fn($item) => OrderLineItemDTO::fromShopifyResponse($item['node'] ?? $item),
                $lineItems
            ),
            shippingAddress: $data['shippingAddress'] ?? null,
        );
    }

    /**
     * Get the total number of items in the order.
     * 
     * Sums the quantity of all line items.
     * 
     * @return int
     */
    public function getTotalItems(): int
    {
        return array_reduce(
            $this->lineItems,
            fn($sum, $item) => $sum + $item->quantity,
            0
        );
    }
}
