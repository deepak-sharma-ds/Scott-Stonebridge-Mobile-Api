<?php

namespace App\Models\Bundles;

use App\Models\User;
use App\Services\Bundles\Gid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bundle extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT  = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public const SYNC_PENDING = 'pending';
    public const SYNC_SYNCED  = 'synced';
    public const SYNC_FAILED  = 'failed';

    protected $fillable = [
        'shop_domain',
        'title',
        'description',
        'auto_media_ids',
        'pending_media_deletes',
        'status',
        'sync_status',
        'shopify_product_id',
        'shopify_variant_id',
        'shopify_handle',
        'price',
        'compare_at_price',
        'map_floor',
        'offer_on_product_page',
        'offer_in_cart',
        'offer_heading',
        'offer_include_components',
        'offer_extra_products',
        'offer_synced_product_ids',
        'needs_review',
        'review_reason',
        'last_synced_at',
        'last_sync_error',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price'            => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'map_floor'        => 'decimal:2',
        'needs_review'     => 'boolean',
        'last_synced_at'   => 'datetime',

        'offer_on_product_page'    => 'boolean',
        'offer_in_cart'            => 'boolean',
        'offer_include_components' => 'boolean',
        'offer_extra_products'     => 'array',
        'offer_synced_product_ids' => 'array',
        'auto_media_ids'           => 'array',
        'pending_media_deletes'    => 'array',
    ];

    public function components()
    {
        return $this->hasMany(BundleComponent::class)->orderBy('position');
    }

    public function images()
    {
        return $this->hasMany(BundleImage::class)->orderBy('position');
    }

    public function syncLogs()
    {
        return $this->hasMany(BundleSyncLog::class)->latest();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Products whose page/cart should promote this bundle: the products chosen in
     * "Show this offer on", plus the component products when that option is on.
     * Empty when the offer is not live.
     */
    public function offerTargetProductIds(): array
    {
        if (! $this->isActive() || ! $this->shopify_product_id || $this->trashed()
            || (! $this->offer_on_product_page && ! $this->offer_in_cart)) {
            return [];
        }

        return collect($this->offer_extra_products ?? [])->pluck('id')
            ->merge($this->offer_include_components ? $this->components->pluck('shopify_product_id') : [])
            ->filter()
            ->reject(fn ($id) => $id === $this->shopify_product_id)
            ->unique()
            ->values()
            ->all();
    }

    public function getSavingsAmountAttribute(): float
    {
        if ($this->compare_at_price === null) {
            return 0.0;
        }

        return max(0, round((float) $this->compare_at_price - (float) $this->price, 2));
    }

    public function getSavingsPercentAttribute(): float
    {
        $total = (float) $this->compare_at_price;

        return $total > 0 ? round($this->savings_amount / $total * 100, 1) : 0.0;
    }

    /**
     * Link to the bundle product in the Shopify admin.
     */
    public function getShopifyAdminUrlAttribute(): ?string
    {
        if (! $this->shopify_product_id) {
            return null;
        }

        $store = str_replace('.myshopify.com', '', $this->shop_domain);

        return "https://admin.shopify.com/store/{$store}/products/" . Gid::toId($this->shopify_product_id);
    }
}
