<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class BaseResource extends JsonResource
{
    /**
     * Format timestamp to ISO 8601
     */
    protected function formatTimestamp(?\DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d\TH:i:s\Z');
    }
}
