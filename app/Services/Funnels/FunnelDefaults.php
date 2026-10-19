<?php

namespace App\Services\Funnels;

/**
 * Defaults for every funnel section, and a merge that keeps only known keys.
 * Texts follow the reference app (UFE) wording.
 */
class FunnelDefaults
{
    public static function trigger(): array
    {
        return [
            'all'         => false,
            'cart_value'  => ['enabled' => false, 'min' => 0],
            'products'    => [],  // [{id, title, image, handle}]
            'collections' => [],  // [{id, title}]
            'tags'        => [],  // ["tag"]
        ];
    }

    public static function placements(): array
    {
        return [
            'product_page' => ['enabled' => true, 'style' => 'inpage'], // inpage | popup
            'cart_page'    => ['enabled' => false],                     // popup
        ];
    }

    public static function settings(): array
    {
        return [
            'hide_out_of_stock'  => true,
            'show_compare_price' => true,
            'hide_once_rejected' => false,
            'allow_single'       => false,       // popups: each offer product can also be added on its own (regular price)
            'after_accept'       => 'cart',      // cart | checkout | stay
            'reject_behaviour'   => 'continue',  // continue | checkout (cart popup)
            'popup_delay'        => 0,           // seconds
            'combine_with_order_discounts'    => true,
            'combine_with_shipping_discounts' => true,
            'starts_at'          => null,        // ISO 8601
            'ends_at'            => null,
        ];
    }

    public static function design(): array
    {
        return [
            'font'          => 'inherit',   // inherit | system
            'image_fit'     => 'contain',   // contain | cover
            'radius'        => 8,
            'primary'       => '#6691CD',
            'accept_bg'     => '#6C4A9D',
            'accept_text'   => '#ffffff',
            'reject_bg'     => '#ffffff',
            'reject_text'   => '#555555',
            'sale_price'    => '#6C4A9D',
            'compare_price' => '#4b4b4b',
            'title_color'   => '#4b4b4b',
            'text_color'    => '#4b4b4b',
            'badge_bg'      => '#6691CD',
            'badge_text'    => '#ffffff',
            'card_bg'       => '#ffffff',
            'popup_bg'      => '#ffffff',
            'border_color'  => '#e3e3e3',
            'custom_css'    => '',
        ];
    }

    public static function translations(): array
    {
        return [
            'title'          => 'Save even more with this bundle',
            'popup_title'    => "Congrats!! 🎉 You've unlocked a bundle offer!",
            'bundle_badge'   => 'BUNDLE OFFER',
            'save_text'      => 'Save {{DISCOUNT}}',
            'total_text'     => 'Total',
            'accept_button'  => 'Yes, I want',
            'bundle_button'  => 'Add bundle to cart',
            'reject_button'  => 'No, thanks',
            'added_text'     => 'Added to cart',
            'sold_out_text'  => 'Sold out',
            'unavailable_text' => 'Currently unavailable',
            'single_button'  => 'Add',
            'single_added'   => 'Added',
            'remove_bundle'  => 'Remove bundle',
            'removed_text'   => 'A bundle product was removed, so the rest of the bundle was removed too.',
            'max_text'       => "You've reached the maximum quantity for this product.",
            'min_text'       => "This product can't go below its minimum quantity in the bundle.",
            'discount_title' => 'BUNDLE OFFER {{DISCOUNT}} OFF', // shown on each line at checkout
        ];
    }

    /**
     * Merge input over defaults, keeping only known keys (nested one level for trigger/placements).
     */
    public static function merge(string $section, ?array $input): array
    {
        $defaults = static::{$section}();
        $input = $input ?? [];
        $result = [];

        foreach ($defaults as $key => $default) {
            $value = $input[$key] ?? $default;
            if (is_array($default) && ! array_is_list($default) && is_array($value)) {
                $value = array_merge($default, array_intersect_key($value, $default));
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
