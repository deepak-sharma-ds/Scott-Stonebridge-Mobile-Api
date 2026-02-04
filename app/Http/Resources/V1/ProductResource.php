<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\BaseResource;

class ProductResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'handle' => $this->handle,
            'description' => $this->description,
            'vendor' => $this->vendor,
            'product_type' => $this->productType,
            'tags' => $this->tags,
            'available_for_sale' => $this->availableForSale,
            'images' => $this->images->map(fn($img) => [
                'url' => $img->url,
                'alt_text' => $img->altText,
                'width' => $img->width,
                'height' => $img->height,
            ]),
            'variants' => $this->variants->map(fn($variant) => [
                'id' => $variant->id,
                'title' => $variant->title,
                'sku' => $variant->sku,
                'price' => [
                    'amount' => $variant->price->amount,
                    'currency' => $variant->price->currencyCode,
                    'formatted' => $variant->price->formatted(),
                ],
                'compare_at_price' => $variant->compareAtPrice ? [
                    'amount' => $variant->compareAtPrice->amount,
                    'currency' => $variant->compareAtPrice->currencyCode,
                    'formatted' => $variant->compareAtPrice->formatted(),
                ] : null,
                'available_for_sale' => $variant->availableForSale,
                'quantity_available' => $variant->quantityAvailable,
            ]),
            'options' => $this->options,
            'created_at' => $this->formatTimestamp($this->createdAt),
            'updated_at' => $this->formatTimestamp($this->updatedAt),
        ];
    }
}
