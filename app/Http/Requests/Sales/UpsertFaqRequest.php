<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\BaseApiRequest;

/**
 * Payload validation for POST /api/v1/ai/knowledge/faq.
 *
 * Merchant/internal endpoint guarded by shopify.auth — the request body
 * is trusted authoring input. We still cap field sizes so the prompt
 * builder cannot be flooded with multi-megabyte FAQs.
 */
class UpsertFaqRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'shop_domain' => ['required', 'string', 'max:255'],
            'question' => ['required', 'string', 'min:3', 'max:255'],
            'answer' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
