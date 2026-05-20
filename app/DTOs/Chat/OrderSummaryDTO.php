<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Compact order row used by the `order_list` SSE chunk (response to
 * "show me my recent orders"). Detail / tracking comes via OrderTrackingDTO.
 */
class OrderSummaryDTO extends BaseDTO
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $status,
        public readonly ?string $financialStatus,
        public readonly ?int $totalMinorUnits,
        public readonly ?string $currency,
        public readonly ?string $createdAt,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateRequired($this->orderNumber, 'order_number');
        $this->validateRequired($this->status, 'status');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_number' => $this->orderNumber,
            'status' => $this->status,
            'financial_status' => $this->financialStatus,
            'total_minor_units' => $this->totalMinorUnits,
            'currency' => $this->currency,
            'created_at' => $this->createdAt,
        ];
    }
}
