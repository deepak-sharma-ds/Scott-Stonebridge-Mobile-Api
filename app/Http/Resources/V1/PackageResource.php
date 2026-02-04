<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\BaseResource;

class PackageResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'shopify_tag' => $this->shopifyTag,
            'cover_image' => $this->coverImage,
            'status' => $this->status,
        ];
    }
}
