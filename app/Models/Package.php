<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function audios()
    {
        return $this->hasMany(Audio::class)->orderBy('order_index');
    }

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
