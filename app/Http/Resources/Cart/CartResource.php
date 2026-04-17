<?php

namespace App\Http\Resources\Cart;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Cart API Resource
 * 
 * Transforms CartDTO data to API response format.
 * Removes Shopify internal fields and includes calculated fields.
 * 
 * Requirements: 17.2, 17.6, 17.7, 17.8
 */
class CartResource extends BaseApiResource
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
            'checkout_url' => $this->checkoutUrl,
            'line_items' => CartLineItemResource::collection($this->lineItems),
            'subtotal' => $this->cost['subtotal'],
            'total' => $this->cost['total'],
            'currency' => $this->cost['currency'],
            'total_items' => $this->getTotalItems(),
            'unique_items' => count($this->lineItems),
            'buyer_identity' => $this->buyerIdentity,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
