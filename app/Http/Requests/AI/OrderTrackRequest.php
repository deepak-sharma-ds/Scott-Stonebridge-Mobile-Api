<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Http\Requests\BaseApiRequest;

/**
 * Validates the GET /api/v1/ai/orders/track query string. session_id is
 * required so the rate limiter has a stable key — the order lookup itself
 * doesn't need a live conversation, but the rate-limit bucket does.
 */
class OrderTrackRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'shop_domain' => ['required', 'string', 'max:255'],
            'session_id' => ['required', 'string', 'uuid'],
            'order_number' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ];
    }
}
