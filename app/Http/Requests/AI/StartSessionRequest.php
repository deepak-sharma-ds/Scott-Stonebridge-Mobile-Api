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
            'theme_settings' => ['sometimes', 'nullable', 'array'],
            'theme_settings.persona_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'theme_settings.avatar_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'theme_settings.brand_color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'theme_settings.widget_position' => ['sometimes', 'nullable', 'string', 'max:30'],
            'theme_settings.greeting_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'theme_settings.free_shipping_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
