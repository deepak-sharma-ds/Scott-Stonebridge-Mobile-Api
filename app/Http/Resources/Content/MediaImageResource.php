<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Media Image API Resource
 *
 * Transforms MediaImageDTO data to API response format.
 */
class MediaImageResource extends BaseApiResource
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
            'url' => $this->url,
            'alt_text' => $this->altText,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
