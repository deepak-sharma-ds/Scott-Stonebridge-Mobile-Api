<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Order API Resource
 * 
 * Transforms OrderDTO data to API response format.
 * Removes Shopify internal fields and includes calculated fields.
 * 
 * Requirements: 17.3, 17.6, 17.7, 17.8
 */
class OrderResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     * 
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'order_number' => $this->orderNumber,
            'processed_at' => $this->processedAt,
            'financial_status' => $this->financialStatus,
            'fulfillment_status' => $this->fulfillmentStatus,
            'total_price' => $this->totalPrice['amount'],
            'subtotal_price' => $this->subtotalPrice['amount'],
            'total_tax' => $this->totalTax['amount'],
            'currency' => $this->totalPrice['currency'],
            'line_items' => OrderLineItemResource::collection($this->lineItems),
            'total_items' => $this->getTotalItems(),
            'unique_items' => count($this->lineItems),
            'shipping_address' => $this->shippingAddress,
        ];
    }
}
