<?php

namespace App\Models\Funnels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Funnel extends Model
{
    use SoftDeletes;

    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    protected $fillable = [
        'shop_domain', 'name', 'status', 'trigger', 'placements', 'settings', 'design', 'translations',
        'published_at', 'discount_ids', 'sync_status', 'last_sync_error', 'last_synced_at', 'needs_review', 'review_reason', 'bundle_id',
    ];

    protected $casts = [
        'trigger'        => 'array',
        'placements'     => 'array',
        'settings'       => 'array',
        'design'         => 'array',
        'translations'   => 'array',
        'discount_ids'   => 'array',
        'needs_review'   => 'boolean',
        'last_synced_at' => 'datetime',
        'published_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        // First time live: from then on an inactive funnel is Paused, before that it is a Draft.
        static::saving(function (Funnel $funnel) {
            if ($funnel->status === self::ACTIVE && ! $funnel->published_at) {
                $funnel->published_at = now();
            }
        });
    }

    public function items()
    {
        return $this->hasMany(FunnelItem::class)->orderBy('position');
    }

    public function stats()
    {
        return $this->hasMany(FunnelStat::class);
    }

    public function orders()
    {
        return $this->hasMany(FunnelOrder::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Live on the storefront right now (active and inside the schedule, if any).
     */
    public function isLive(?\DateTimeInterface $at = null): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();
        $start = $this->settings['starts_at'] ?? null;
        $end = $this->settings['ends_at'] ?? null;

        return (! $start || $at->gte($start)) && (! $end || $at->lte($end));
    }

    /**
     * Pages this funnel shows on, for tables: ["Productpage", "Cartpage"].
     */
    public function pageLabels(): array
    {
        $labels = [];
        if ($this->placements['product_page']['enabled'] ?? false) {
            $labels[] = 'Productpage';
        }
        if ($this->placements['cart_page']['enabled'] ?? false) {
            $labels[] = 'Cartpage';
        }

        return $labels;
    }
}
