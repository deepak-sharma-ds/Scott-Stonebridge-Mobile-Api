<?php

namespace App\Models\Funnels;

use Illuminate\Database\Eloquent\Model;

class FunnelItem extends Model
{
    public const PERCENTAGE = 'percentage';
    public const FIXED = 'fixed';
    public const NONE = 'none';

    protected $fillable = [
        'funnel_id', 'product_id', 'variant_id', 'product_title', 'variant_title', 'handle', 'sku',
        'image_url', 'price', 'quantity', 'max_quantity', 'discount_type', 'discount_value', 'position',
    ];

    protected $casts = [
        'price'          => 'decimal:2',
        'discount_value' => 'decimal:2',
        'quantity'       => 'integer',
        'max_quantity'   => 'integer',
        'position'       => 'integer',
    ];

    public function funnel()
    {
        return $this->belongsTo(Funnel::class);
    }

    public function getTitleAttribute(): string
    {
        return $this->variant_title && $this->variant_title !== 'Default Title'
            ? "{$this->product_title} — {$this->variant_title}"
            : $this->product_title;
    }

    /**
     * Unit price after this item's discount (never below 0).
     */
    /**
     * Price of one unit after the bundle discount. Percentage is off each unit; a fixed amount is off the
     * product's line in the bundle (all its units together), so per unit it is amount / bundle quantity.
     */
    public static function discountedPrice(float $price, string $type, float $value, int $quantity = 1): float
    {
        return match ($type) {
            self::PERCENTAGE => round(max(0, $price * (1 - $value / 100)), 2),
            self::FIXED      => round(max(0, $price - $value / max(1, $quantity)), 2),
            default          => round($price, 2),
        };
    }

    /**
     * The product's line in the bundle (price × quantity) after the bundle discount.
     */
    public static function discountedLine(float $price, string $type, float $value, int $quantity = 1): float
    {
        $quantity = max(1, $quantity);

        return $type === self::FIXED
            ? round(max(0, $price * $quantity - $value), 2)
            : round(self::discountedPrice($price, $type, $value) * $quantity, 2);
    }

    public function getDiscountedPriceAttribute(): float
    {
        return self::discountedPrice((float) $this->price, $this->discount_type, (float) $this->discount_value, (int) $this->quantity);
    }
}
