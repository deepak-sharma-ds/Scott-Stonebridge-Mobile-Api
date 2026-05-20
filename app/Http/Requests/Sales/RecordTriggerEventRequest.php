<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\BaseApiRequest;

/**
 * Payload for POST /api/v1/ai/triggers/event. The endpoint always returns
 * 200 — failures are absorbed silently because firing analytics must never
 * break the storefront flow.
 */
class RecordTriggerEventRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $allowed = (array) config('sales.triggers.event_types', ['trigger_opened', 'trigger_dismissed']);

        return [
            'session_id' => ['required', 'string', 'max:64'],
            'shop_domain' => ['required', 'string', 'max:255'],
            'event' => ['required', 'string', 'in:'.implode(',', $allowed)],
            'trigger_type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
