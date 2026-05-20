<?php

declare(strict_types=1);

namespace App\Http\Resources\AI;

use App\DTOs\Chat\ProductRecommendationDTO;
use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * @mixin ProductRecommendationDTO
 */
class ProductRecommendationResource extends BaseApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductRecommendationDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'title' => $dto->title,
            'handle' => $dto->handle,
            'vendor' => $dto->vendor,
            'price' => $dto->price,
            'currency' => $dto->currency,
            'image' => $dto->image,
            'available' => $dto->available,
            'url' => $dto->url,
        ];
    }
}
