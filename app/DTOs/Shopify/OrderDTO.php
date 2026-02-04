<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

use App\DTOs\BaseDTO;
use Illuminate\Support\Carbon;

class OrderDTO extends BaseDTO
{
    public function __construct(
        public string $id,
        public string $name, // e.g., "#1001"
        public string $financialStatus, // PAID, PENDING
        public string $fulfillmentStatus, // FULFILLED, UNFULFILLED
        public MoneyDTO $totalPrice,
        public MoneyDTO $subtotalPrice,
        public MoneyDTO $totalTax,
        public MoneyDTO $totalShipping,
        public ?string $processedAt,
        public ?string $currencyCode,
        public array $lines = [], // array of OrderLineItemDTO (or similar)
        public ?array $shippingAddress = null,
        public ?string $customerUrl = null
    ) {}

    public static function fromShopifyNode(array $node): self
    {
        return new self(
            id: $node['id'],
            name: $node['name'],
            financialStatus: $node['financialStatus'] ?? 'UNKNOWN',
            fulfillmentStatus: $node['fulfillmentStatus'] ?? 'UNFULFILLED',
            totalPrice: new MoneyDTO(
                (float) ($node['totalPriceV2']['amount'] ?? $node['totalPrice']['amount'] ?? 0),
                $node['totalPriceV2']['currencyCode'] ?? $node['totalPrice']['currencyCode'] ?? 'USD'
            ),
            subtotalPrice: new MoneyDTO(
                (float) ($node['subtotalPriceV2']['amount'] ?? $node['subtotalPrice']['amount'] ?? 0),
                $node['subtotalPriceV2']['currencyCode'] ?? $node['subtotalPrice']['currencyCode'] ?? 'USD'
            ),
            totalTax: new MoneyDTO(
                (float) ($node['totalTaxV2']['amount'] ?? $node['totalTax']['amount'] ?? 0),
                $node['totalTaxV2']['currencyCode'] ?? $node['totalTax']['currencyCode'] ?? 'USD'
            ),
            totalShipping: new MoneyDTO(
                (float) ($node['totalShippingPriceV2']['amount'] ?? $node['totalShippingPrice']['amount'] ?? 0),
                $node['totalShippingPriceV2']['currencyCode'] ?? $node['totalShippingPrice']['currencyCode'] ?? 'USD'
            ),
            processedAt: $node['processedAt'] ?? null,
            currencyCode: $node['currencyCode'] ?? 'USD',
            lines: isset($node['lineItems']['edges']) 
                ? array_map(fn($edge) => $edge['node'], $node['lineItems']['edges']) 
                : [],
            shippingAddress: $node['shippingAddress'] ?? null,
            customerUrl: $node['statusUrl'] ?? null
        );
    }
}
