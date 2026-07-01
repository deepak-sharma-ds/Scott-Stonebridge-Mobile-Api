<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use App\DTOs\Base\BaseDTO;

/**
 * One row in the customer's order-history list rendered by the chat widget's
 * `order_list` chunk. Deliberately lighter than OrderTrackingDTO — no
 * tracking/carrier detail — because the list links each order out to its
 * Shopify order-detail page (`orderUrl`) where the full breakdown lives.
 */
class CustomerOrderSummaryDTO extends BaseDTO
{
    public function __construct(
        public readonly string $orderNumber,    // "22467" — no leading '#'
        public readonly string $name,           // "#22467" — display label
        public readonly string $status,         // one of OrderTrackingDTO::STATUSES
        public readonly ?string $processedAt,    // ISO 8601 date or null
        public readonly ?string $totalAmount,    // "0.00" or null
        public readonly ?string $currencyCode,   // "GBP" or null
        public readonly ?string $orderUrl,       // Shopify order-detail page URL
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateRequired($this->orderNumber, 'orderNumber');
        $this->validateRequired($this->status, 'status');
        $this->validateInArray($this->status, OrderTrackingDTO::STATUSES, 'status');
    }

    /**
     * Render snake_case JSON keys to stay consistent with the rest of the
     * /api/v1/ai/* chunk envelope (mirrors OrderTrackingDTO::toArray).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_number' => $this->orderNumber,
            'name' => $this->name,
            'status' => $this->status,
            'processed_at' => $this->processedAt,
            'total' => $this->totalAmount,
            'currency' => $this->currencyCode,
            'order_url' => $this->orderUrl,
        ];
    }
}
