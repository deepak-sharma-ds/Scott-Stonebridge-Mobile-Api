<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Related product card resource.
 */
class RelatedProductResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $firstVariant = $this->variants[0] ?? null;
        $firstImage = $this->images[0] ?? null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'handle' => $this->handle,
            'image' => $firstImage['url'] ?? null,
            'price' => $firstVariant?->price,
            'currency' => $firstVariant?->currencyCode,
        ];
    }
}
