<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\BaseApiRequest;

/**
 * Payload validation for POST /api/v1/ai/upsell/suggestions.
 *
 * Cart items are optional — when the cart is empty the endpoint still
 * returns the free-shipping threshold so the storefront can render the
 * "spend X more for free shipping" prompt with a zero-item baseline.
 */
class GetUpsellSuggestionsRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'max:64'],
            'shop_domain' => ['required', 'string', 'max:255'],
            'cart_items' => ['sometimes', 'array'],
            'cart_items.*.product_id' => ['sometimes', 'string', 'max:255'],
            'cart_items.*.id' => ['sometimes', 'string', 'max:255'],
            'cart_items.*.quantity' => ['sometimes', 'integer', 'min:1'],
            'cart_total' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ];
    }
}
