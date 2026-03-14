<?php

namespace App\Http\Resources\Navigation;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Menu API Resource
 * 
 * Transforms MenuDTO data to API response format
 */
class MenuResource extends BaseApiResource
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
            'handle' => $this->handle,
            'title' => $this->title,
            'items' => MenuItemResource::collection($this->items),
        ];
    }
}
