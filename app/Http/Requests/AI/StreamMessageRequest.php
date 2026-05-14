<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Http\Requests\BaseApiRequest;

/**
 * Validates the payload sent to the SSE streaming endpoint. The session_id
 * is taken from the route parameter so we don't repeat it in the body.
 */
class StreamMessageRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $maxLength = (int) config('chatbot.message.max_length', 2000);

        return [
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
