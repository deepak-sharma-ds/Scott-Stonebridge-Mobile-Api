<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\BaseApiRequest;

/**
 * Payload validation for POST /api/v1/ai/analytics/event.
 *
 * Endpoint always returns 200 (analytics ingestion must not break UX),
 * but invalid payloads still get 422 — the storefront should NOT be
 * sending malformed events in production.
 */
class StoreConversionEventRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $allowed = (array) config('sales.analytics.event_types', []);

        return [
            'session_id' => ['required', 'string', 'max:64'],
            'shop_domain' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'in:'.implode(',', $allowed)],
            'product_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'order_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'revenue' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
