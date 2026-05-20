<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Http\Requests\BaseApiRequest;

/**
 * Validates the payload that creates a brand-new chat session.
 * Customer identity is optional — guests are allowed.
 */
class StartSessionRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'shop_domain' => ['required', 'string', 'max:255'],
            'page_type' => ['sometimes', 'nullable', 'string', 'in:home,product,collection,cart,search,account,blog,page,unknown'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            // Phase F — explicit Shopify-side locale wins over generic `locale`.
            'shopify_locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'shopify_customer_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
