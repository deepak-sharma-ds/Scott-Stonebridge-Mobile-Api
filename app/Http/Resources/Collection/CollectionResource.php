<?php

namespace App\Http\Resources\Collection;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Collection API Resource
 * 
 * Transforms CollectionDTO data to API response format.
 * Removes Shopify internal fields and flattens nested structures.
 * 
 * Requirements: 17.5, 17.6, 17.7, 17.8
 */
class CollectionResource extends BaseApiResource
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
            'image' => $this->image,
            'updated_at' => $this->updatedAt,
        ];
    }
}
