<?php

namespace App\Http\Resources\Navigation;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Menu Item API Resource
 * 
 * Transforms MenuItemDTO data to API response format
 */
class MenuItemResource extends BaseApiResource
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
            'url' => $this->url,
            'api_endpoint' => $this->apiEndpoint,
            'params' => $this->params,
            'type' => $this->type,
            'items' => self::collection($this->items),
        ];
    }
}
