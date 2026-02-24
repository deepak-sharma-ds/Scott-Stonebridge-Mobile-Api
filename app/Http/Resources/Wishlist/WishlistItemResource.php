<?php

namespace App\Http\Resources\Wishlist;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Wishlist Item API Resource
 * 
 * Transforms WishlistItemDTO data to API response format.
 * Includes essential product information for wishlist display.
 * 
 * Requirements: 12.5, 12.9, 12.10, 12.11
 */
class WishlistItemResource extends BaseApiResource
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
            'product_id' => $this->productId,
            'title' => $this->title,
            'handle' => $this->handle,
            'image' => $this->image,
            'price' => $this->price,
            'currency' => $this->currency,
            'available_for_sale' => $this->availableForSale,
            'added_at' => $this->addedAt,
        ];
    }
}
