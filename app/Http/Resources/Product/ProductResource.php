<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Product API Resource
 * 
 * Transforms ProductDTO data to API response format.
 * Removes Shopify internal fields and flattens nested structures.
 * 
 * Requirements: 17.1, 17.6, 17.7, 17.8
 */
class ProductResource extends BaseApiResource
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
            'handle' => $this->handle,
            'description' => $this->description,
            'descriptionHtml' => $this->descriptionHtml,
            'vendor' => $this->vendor,
            'product_type' => $this->productType,
            'tags' => $this->tags,
            'available_for_sale' => $this->availableForSale,
            'images' => $this->images,
            'variants' => ProductVariantResource::collection($this->variants),
            'options' => $this->options,
            'published_at' => $this->publishedAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
