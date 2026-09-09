<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceToken extends Model
{
    use HasFactory;

    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    protected $fillable = [
        'shopify_customer_id',
        'customer_email',
        'fcm_token',
        'platform',
        'device_id',
        'app_version',
        'push_enabled',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_customer_id' => 'integer',
            'push_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function pushNotifications(): HasMany
    {
        return $this->hasMany(PushNotification::class);
    }

    /**
     * Devices eligible to receive pushes: not revoked and not opted out.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('push_enabled', true);
    }

    /**
     * Match devices registered for the given recipient email
     * (Klaviyo profiles are keyed by email).
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('customer_email', strtolower(trim($email)));
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
