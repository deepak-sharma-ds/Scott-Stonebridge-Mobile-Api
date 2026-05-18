<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\BaseApiRequest;
use App\Models\AiLead;

/**
 * Payload validation for POST /api/v1/ai/leads/capture.
 *
 * session_id must reference an existing ai_conversations row — guests are
 * allowed but the conversation envelope is mandatory so abandon recovery
 * has somewhere to anchor.
 */
class CaptureLeadRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $sources = (array) config('sales.leads.sources', [
            AiLead::SOURCE_PROACTIVE_TRIGGER,
            AiLead::SOURCE_MANUAL_INPUT,
            AiLead::SOURCE_ESCALATION,
        ]);

        return [
            'session_id' => ['required', 'string', 'max:64', 'exists:ai_conversations,session_id'],
            'shop_domain' => ['required', 'string', 'max:255'],
            // rfc only — dns lookups would slow the request and fail in CI.
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issue_summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cart_snapshot' => ['sometimes', 'nullable', 'array'],
            'cart_snapshot.item_count' => ['sometimes', 'integer', 'min:0'],
            'cart_snapshot.total_price' => ['sometimes', 'numeric', 'min:0'],
            'cart_snapshot.items' => ['sometimes', 'array'],
            'source' => ['required', 'string', 'in:'.implode(',', $sources)],
        ];
    }
}
