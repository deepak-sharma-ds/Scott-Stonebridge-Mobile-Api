<?php

declare(strict_types=1);

namespace App\Http\Resources\AI;

use App\Http\Resources\Base\BaseApiResource;
use App\Models\AiConversation;
use Illuminate\Http\Request;

/**
 * @mixin AiConversation
 */
class ConversationResource extends BaseApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->session_id,
            'shop_domain' => $this->shop_domain,
            'shopify_customer_id' => $this->shopify_customer_id,
            'page_type' => $this->page_type,
            'locale' => $this->locale,
            'status' => $this->status,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'ended_at' => optional($this->ended_at)->toIso8601String(),
        ];
    }
}
