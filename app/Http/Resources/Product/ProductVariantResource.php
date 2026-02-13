<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Product Variant API Resource
 * 
 * Transforms ProductVariantDTO data to API response format.
 * Removes Shopify internal fields and formats pricing information.
 * 
 * Requirements: 17.1, 17.6, 17.7, 17.8
 */
class ProductVariantResource extends BaseApiResource
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
            'title' => $this->title,
            'sku' => $this->sku,
            'price' => $this->price,
            'currency_code' => $this->currencyCode,
            'compare_at_price' => $this->compareAtPrice,
            'available_for_sale' => $this->availableForSale,
            'quantity_available' => $this->quantityAvailable,
            'image' => $this->image,
            'selected_options' => $this->selectedOptions,
            'weight' => $this->weight,
            'weight_unit' => $this->weightUnit,
        ];
    }
}
