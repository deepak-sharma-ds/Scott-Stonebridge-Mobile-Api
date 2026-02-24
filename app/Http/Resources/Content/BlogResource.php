<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Blog API Resource
 * 
 * Transforms BlogDTO data to API response format.
 * Provides basic blog information for listing.
 * 
 * Requirements: 12.7, 12.9, 12.10, 12.11
 */
class BlogResource extends BaseApiResource
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
        ];
    }
}
