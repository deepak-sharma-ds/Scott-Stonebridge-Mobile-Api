<?php

namespace App\Http\Requests\ShopifyApp;

use App\Services\Funnels\FunnelDefaults;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Funnel create/update from the embedded app (auth: Shopify session token middleware).
 */
class FunnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Texts are rendered on the storefront: keep them plain.
        $translations = collect((array) $this->input('translations', []))
            ->map(fn ($v) => is_string($v) ? trim(strip_tags($v)) : $v)
            ->all();

        $design = (array) $this->input('design', []);
        if (isset($design['custom_css']) && is_string($design['custom_css'])) {
            // CSS only: no markup, no closing of the style element.
            $design['custom_css'] = str_ireplace(['</style', '<', '>'], '', $design['custom_css']);
        }

        $this->merge([
            'name'         => trim((string) $this->input('name')),
            'items'        => array_values((array) $this->input('items', [])),
            'translations' => $translations,
            'design'       => $design,
        ]);
    }

    public function rules(): array
    {
        $color = ['nullable', 'string', 'regex:/^(#[0-9a-fA-F]{3,8}|transparent)$/'];
        $text = ['nullable', 'string', 'max:255'];

        $rules = [
            'name'   => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],

            'items'                  => ['required', 'array', 'min:1', 'max:20'],
            'items.*.variant_id'     => ['required', 'string', 'starts_with:gid://shopify/ProductVariant/'],
            'items.*.quantity'       => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.max_quantity'   => ['nullable', 'integer', 'min:1', 'max:999'],
            'items.*.discount_type'  => ['required', 'in:percentage,fixed,none'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:99999'],

            'trigger'                    => ['required', 'array'],
            'trigger.all'                => ['nullable', 'boolean'],
            'trigger.cart_value.enabled' => ['nullable', 'boolean'],
            'trigger.cart_value.min'     => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'trigger.products'           => ['nullable', 'array', 'max:250'],
            'trigger.products.*.id'      => ['required', 'string', 'starts_with:gid://shopify/Product/'],
            'trigger.products.*.title'   => ['nullable', 'string', 'max:255'],
            'trigger.products.*.image'   => ['nullable', 'string', 'max:1000'],
            'trigger.products.*.handle'  => ['nullable', 'string', 'max:255'],
            'trigger.collections'        => ['nullable', 'array', 'max:100'],
            'trigger.collections.*.id'   => ['required', 'string', 'starts_with:gid://shopify/Collection/'],
            'trigger.collections.*.title' => ['nullable', 'string', 'max:255'],
            'trigger.tags'               => ['nullable', 'array', 'max:50'],
            'trigger.tags.*'             => ['string', 'max:255'],

            'placements.product_page.enabled' => ['nullable', 'boolean'],
            'placements.product_page.style'   => ['nullable', 'in:inpage,popup'],
            'placements.cart_page.enabled'    => ['nullable', 'boolean'],

            'settings.hide_out_of_stock'  => ['nullable', 'boolean'],
            'settings.show_compare_price' => ['nullable', 'boolean'],
            'settings.hide_once_rejected' => ['nullable', 'boolean'],
            'settings.allow_single'       => ['nullable', 'boolean'],
            'settings.after_accept'       => ['nullable', 'in:cart,checkout,stay'],
            'settings.reject_behaviour'   => ['nullable', 'in:continue,checkout'],
            'settings.popup_delay'        => ['nullable', 'integer', 'min:0', 'max:30'],
            'settings.combine_with_order_discounts'    => ['nullable', 'boolean'],
            'settings.combine_with_shipping_discounts' => ['nullable', 'boolean'],
            'settings.starts_at'          => ['nullable', 'date'],
            'settings.ends_at'            => ['nullable', 'date'],

            'design.font'       => ['nullable', 'in:inherit,system'],
            'design.image_fit'  => ['nullable', 'in:contain,cover'],
            'design.radius'     => ['nullable', 'integer', 'min:0', 'max:30'],
            'design.custom_css' => ['nullable', 'string', 'max:5000'],
        ];

        foreach (array_keys(FunnelDefaults::design()) as $key) {
            if (! isset($rules["design.{$key}"])) {
                $rules["design.{$key}"] = $color;
            }
        }
        foreach (array_keys(FunnelDefaults::translations()) as $key) {
            $rules["translations.{$key}"] = $text;
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'items.required'   => 'Add at least one offer product.',
            'design.*.regex'   => 'Colours must be hex values like #197cff.',
        ];
    }

    /**
     * Validated data with settings dates normalised to ISO 8601 (UTC).
     */
    public function funnelData(): array
    {
        $data = $this->validated();
        foreach (['starts_at', 'ends_at'] as $key) {
            if (! empty($data['settings'][$key])) {
                $data['settings'][$key] = \Illuminate\Support\Carbon::parse($data['settings'][$key])->utc()->toIso8601String();
            }
        }

        return $data;
    }
}
