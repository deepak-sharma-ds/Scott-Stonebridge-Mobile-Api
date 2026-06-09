<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Http\Requests\BaseApiRequest;

/**
 * Validates the payload sent to the non-streamed message endpoint.
 *
 * Note: heavy sanitization (HTML / control char strip, jailbreak detection) is
 * applied later in SafetyService — here we only enforce shape + size so the
 * controller can hand a well-formed array to ChatRequestDTO::fromArray().
 */
class SendMessageRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $maxLength = (int) config('chatbot.message.max_length', 2000);

        return [
            // The `exists:` rule used to gate this — but it caused a hard 422
            // for widgets that kept a stale session_id in localStorage after
            // the dev backend URL was swapped. The service layer now self-heals
            // unknown ids via ConversationService::adoptSession, so we keep
            // shape validation only here.
            'session_id' => ['required', 'string', 'uuid'],
            'message' => ['required', 'string', 'min:1', 'max:'.$maxLength],
            'context' => ['sometimes', 'array'],
            'context.page_type' => ['sometimes', 'nullable', 'string', 'in:home,product,collection,cart,search,account,blog,page,unknown'],
            'context.product' => ['sometimes', 'nullable', 'array'],
            'context.cart' => ['sometimes', 'nullable', 'array'],
            'context.customer' => ['sometimes', 'nullable', 'array'],
            'context.recently_viewed' => ['sometimes', 'array'],
            'context.recently_viewed.*' => ['string', 'max:255'],
            'context.shop_domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'context.currency' => ['sometimes', 'nullable', 'string', 'max:8'],
            'context.locale' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }
}
