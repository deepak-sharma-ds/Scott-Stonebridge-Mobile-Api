<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Http\Resources\Base\BaseApiResource;
use App\Models\StoreKnowledge;
use Illuminate\Http\Request;

/**
 * @mixin StoreKnowledge
 */
class KnowledgeResource extends BaseApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_domain' => $this->shop_domain,
            'content_type' => $this->content_type,
            'title' => $this->title,
            'handle' => $this->handle,
            'summary' => $this->summary,
            'last_synced_at' => optional($this->last_synced_at)->toIso8601String(),
        ];
    }
}
