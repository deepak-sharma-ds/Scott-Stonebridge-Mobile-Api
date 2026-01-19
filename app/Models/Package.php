<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Package extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'price',
        'currency',
        'shopify_tag',
        'cover_image',
        'status'
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function audios()
    {
        return $this->hasMany(Audio::class)->orderBy('order_index');
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeWithShopifyTag(Builder $query, string $tag): Builder
    {
        return $query->where('shopify_tag', $tag);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
              ->orWhere('description', 'like', "%{$term}%")
              ->orWhere('shopify_tag', 'like', "%{$term}%");
        });
    }

    public function scopeWithAudioData(Builder $query): Builder
    {
        return $query->with(['audios' => function ($q) {
            $q->select('id', 'package_id', 'title', 'duration_seconds', 'order_index', 'is_hls_ready')
              ->orderBy('order_index');
        }])->withCount('audios');
    }

    /*
    |--------------------------------------------------------------------------
    | Model Events
    |--------------------------------------------------------------------------
    */

    protected static function booted()
    {
        // When soft deleting a package, also soft delete its audios
        static::deleting(function ($package) {
            if (!method_exists($package, 'isForceDeleting') || !$package->isForceDeleting()) {
                $package->audios()->each(function ($audio) {
                    $audio->delete(); // soft delete
                });
            }
        });

        // When restoring a package, restore its audios
        static::restoring(function ($package) {
            $package->audios()->withTrashed()->each(function ($audio) {
                $audio->restore();
            });
        });

        // When permanently deleting a package, delete its audios permanently
        static::forceDeleted(function ($package) {
            $package->audios()->withTrashed()->each(function ($audio) {
                $audio->forceDelete();
            });
        });
    }
}
