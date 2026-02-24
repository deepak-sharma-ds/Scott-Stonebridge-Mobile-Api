<?php

namespace App\Http\Resources\Wishlist;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Wishlist API Resource
 * 
 * Transforms WishlistDTO data to API response format.
 * Includes customer ID, wishlist items, and total count.
 * 
 * Requirements: 12.4, 12.9, 12.10, 12.11
 */
class WishlistResource extends BaseApiResource
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
            'customer_id' => $this->customerId,
            'items' => WishlistItemResource::collection($this->items),
            'total_items' => $this->getTotalItems(),
        ];
    }
}
