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
            'html' => $this->when($this->html !== null, $this->html),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Customize the response for the resource.
     * 
     * Removes HTML escaping flags to allow HTML content to be properly
     * encoded in the JSON response without excessive escaping.
     * 
     * Note: The HTML will still be valid JSON (quotes and backslashes escaped),
     * but HTML tags won't be converted to unicode escape sequences.
     * 
     * @param Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // Use standard JSON encoding without HTML entity escaping
        // This keeps HTML readable while maintaining valid JSON
        $response->setEncodingOptions(
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
