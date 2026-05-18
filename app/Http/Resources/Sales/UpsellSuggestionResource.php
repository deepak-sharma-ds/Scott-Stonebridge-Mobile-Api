<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\DTOs\Sales\UpsellSuggestionDTO;
use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Output shape per upsell candidate. Wraps an UpsellSuggestionDTO rather
 * than an Eloquent model — upsells are pulled fresh from Shopify on every
 * request (with a short cache TTL) and never persisted locally.
 *
 * @mixin UpsellSuggestionDTO
 */
class UpsellSuggestionResource extends BaseApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UpsellSuggestionDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'title' => $dto->title,
            'handle' => $dto->handle,
            'image_url' => $dto->imageUrl,
            'image_alt' => $dto->imageAlt,
            'variant_id' => $dto->variantId,
            'price' => $dto->price,
            'currency' => $dto->currency,
            'available' => $dto->available,
        ];
    }
}
