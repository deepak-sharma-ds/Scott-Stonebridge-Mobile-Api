<?php

namespace App\Http\Resources\Cart;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Cart Line Item API Resource
 * 
 * Transforms CartLineItemDTO data to API response format.
 * Removes Shopify internal fields and formats pricing information.
 * 
 * Requirements: 17.2, 17.6, 17.7, 17.8
 */
class CartLineItemResource extends BaseApiResource
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
            'variant_id' => $this->variantId,
            'product_id' => $this->productId,
            'title' => $this->title,
            'quantity' => $this->quantity,
            'price' => ['amount' => $this->price['amount'], 'currency' => $this->price['currency']],
            'compare_at_price' => $this->compareAtPrice ? ['amount' => $this->compareAtPrice['amount'], 'currency' => $this->compareAtPrice['currency']] : null,
            'image' => $this->image,
            'attributes' => $this->attributes,
        ];
    }
}
