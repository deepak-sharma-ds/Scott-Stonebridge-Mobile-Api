<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\BaseResource;

class CartResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'checkout_url' => $this->checkoutUrl,
            'lines' => $this->lines->map(fn($line) => [
                'id' => $line->id,
                'quantity' => $line->quantity,
                'merchandise_id' => $line->merchandiseId,
                'total_amount' => [
                    'amount' => $line->totalAmount->amount,
                    'currency' => $line->totalAmount->currencyCode,
                    'formatted' => $line->totalAmount->formatted(),
                ],
                'attributes' => $line->attributes,
            ]),
            'total_amount' => [
                'amount' => $this->totalAmount->amount,
                'currency' => $this->totalAmount->currencyCode,
                'formatted' => $this->totalAmount->formatted(),
            ],
            'subtotal_amount' => [
                'amount' => $this->subtotalAmount->amount,
                'currency' => $this->subtotalAmount->currencyCode,
                'formatted' => $this->subtotalAmount->formatted(),
            ],
            'total_tax_amount' => $this->totalTaxAmount ? [
                'amount' => $this->totalTaxAmount->amount,
                'currency' => $this->totalTaxAmount->currencyCode,
                'formatted' => $this->totalTaxAmount->formatted(),
            ] : null,
            'discount_code' => $this->discountCode,
            'discount_amount' => $this->discountAmount ? [
                'amount' => $this->discountAmount->amount,
                'currency' => $this->discountAmount->currencyCode,
                'formatted' => $this->discountAmount->formatted(),
            ] : null,
            'created_at' => $this->formatTimestamp($this->createdAt),
            'updated_at' => $this->formatTimestamp($this->updatedAt),
        ];
    }
}
