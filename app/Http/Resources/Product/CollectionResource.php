<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Collection API Resource
 *
 * Transforms CollectionDTO data to API response format.
 * Removes Shopify internal fields and uses snake_case naming.
 *
 * Requirements: 12.1, 12.9, 12.10, 12.11
 */
class CollectionResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'handle' => $this->handle,
            'description' => $this->description,
            'description_html' => $this->descriptionHtml ?? null,
            'image' => $this->image,
            'products_count' => $this->productsCount,
            'updated_at' => $this->updatedAt,
        ];
    }
}
