<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Page API Resource
 * 
 * Transforms PageDTO data to API response format.
 * Used for CMS pages and policy pages.
 * 
 * Requirements: 12.6, 12.9, 12.10, 12.11
 */
class PageResource extends BaseApiResource
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
            'body' => $this->body,
            'body_summary' => $this->bodySummary,
            'seo' => $this->seo,
            'metafields' => $this->metafields,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
