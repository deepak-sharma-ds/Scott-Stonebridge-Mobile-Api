<?php

namespace App\Http\Resources\Theme;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Theme Template API Resource
 * 
 * Transforms ThemeTemplateDTO data to API response format.
 * Used for theme template endpoints.
 */
class ThemeTemplateResource extends BaseApiResource
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
            'handle' => $this->handle,
            'type' => $this->type,
            'name' => $this->name,
            'suffix' => $this->suffix,
            'sections' => $this->sections,
            'settings' => $this->settings,
            'order' => $this->order,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
