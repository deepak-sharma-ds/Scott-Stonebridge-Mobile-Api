<?php

declare(strict_types=1);

namespace App\Http\Resources\AI;

use App\Http\Resources\Base\BaseApiResource;
use App\Models\AiMessage;
use Illuminate\Http\Request;

/**
 * @mixin AiMessage
 */
class MessageResource extends BaseApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'message' => $this->message,
            'intent' => $this->intent,
            'usage' => [
                'prompt_tokens' => $this->prompt_tokens,
                'completion_tokens' => $this->completion_tokens,
            ],
            'latency_ms' => $this->latency_ms,
            'metadata' => $this->metadata,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
