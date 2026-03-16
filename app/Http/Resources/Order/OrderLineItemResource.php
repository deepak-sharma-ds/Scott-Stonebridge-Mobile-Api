<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Order Line Item API Resource
 * 
 * Transforms OrderLineItemDTO data to API response format.
 * Removes Shopify internal fields and formats pricing information.
 * 
 * Requirements: 17.3, 17.6, 17.7, 17.8
 */
class OrderLineItemResource extends BaseApiResource
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
            'title' => $this->title,
            'quantity' => $this->quantity,
            'price' => $this->discountedTotalPrice['amount'],
            'original_price' => $this->originalTotalPrice['amount'] ?? null,
            'currency' => $this->discountedTotalPrice['currency'],
            'custom_attributes' => $this->customAttributes,
            'variant_id' => $this->variantId,
            'variant_title' => $this->variantTitle,
            'variant_sku' => $this->variantSku,
            'image' => $this->image,
            'image_alt_text' => $this->imageAltText,
            'product_id' => $this->productId,
            'product_title' => $this->productTitle,
            'product_handle' => $this->productHandle,
        ];
    }
}
