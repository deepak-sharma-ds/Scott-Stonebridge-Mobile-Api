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
        public readonly ?array $totalShippingPrice,
        public readonly array $lineItems,
        public readonly ?array $billingAddress,
        public readonly ?array $shippingAddress,
        public readonly array $discountApplications,
        public readonly array $successfulFulfillments,
        public readonly ?string $statusUrl,
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
        $lineItems = $data['lineItems']['edges']
            ?? $data['lineItems']['nodes']
            ?? $data['lineItems']
            ?? [];
        $discountApplications = $data['discountApplications']['nodes']
            ?? $data['discountApplications']['edges']
            ?? $data['discountApplications']
            ?? [];
        $successfulFulfillments = $data['successfulFulfillments']
            ?? [];
        
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
            totalShippingPrice: isset($data['totalShippingPrice']) ? [
                'amount' => $data['totalShippingPrice']['amount'] ?? '0.00',
                'currency' => $data['totalShippingPrice']['currencyCode'] ?? 'GBP',
            ] : null,
            lineItems: array_map(
                fn($item) => OrderLineItemDTO::fromShopifyResponse($item['node'] ?? $item),
                $lineItems
            ),
            billingAddress: $data['billingAddress'] ?? null,
            shippingAddress: $data['shippingAddress'] ?? null,
            discountApplications: array_map(
                fn($item) => $item['node'] ?? $item,
                $discountApplications
            ),
            successfulFulfillments: array_map(
                fn($item) => $item['node'] ?? $item,
                $successfulFulfillments
            ),
            statusUrl: $data['statusUrl'] ?? null,
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
