<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Http\Resources\Base\BaseApiResource;
use App\Models\AiLead;
use Illuminate\Http\Request;

/**
 * @mixin AiLead
 */
class LeadResource extends BaseApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'lead_id' => $this->id,
            'session_id' => $this->session_id,
            'shop_domain' => $this->shop_domain,
            // Echo email back so the frontend can confirm the captured value.
            'email' => $this->email,
            'source' => $this->source,
            'status' => $this->status,
            'has_cart' => $this->hasCartItems(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
