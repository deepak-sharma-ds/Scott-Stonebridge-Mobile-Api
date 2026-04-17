<?php

namespace App\Http\Resources\Home;

use App\Http\Resources\Base\BaseApiResource;
use App\Http\Resources\Product\ProductResource;
use App\Http\Resources\Product\CollectionResource;
use Illuminate\Http\Request;

/**
 * Home API Resource
 * 
 * Transforms HomeDTO data to API response format.
 * Includes featured products, collections, and promotional banners.
 * 
 * Requirements: 12.1, 12.9, 12.10, 12.11
 */
class HomeResource extends BaseApiResource
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
            'featured_products' => ProductResource::collection($this->featuredProducts),
            'collections' => CollectionResource::collection($this->collections),
            'banners' => $this->banners,
        ];
    }
}
