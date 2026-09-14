<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncEvents extends Model
{
    protected $fillable = ['email', 'channel', 'state', 'source', 'synced_at'];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    /**
     * Record that WE just wrote this state to a platform.
     * Call this right after a successful write to Shopify or Klaviyo.
     */
    public static function record(string $email, string $channel, string $state, string $source): void
    {
        self::create([
            'email' => strtolower($email),
            'channel' => $channel,
            'state' => $state,
            'source' => $source,
            'synced_at' => now(),
        ]);
    }

    /**
     * Check whether we ourselves just wrote this exact state within the last
     * $withinSeconds. If true, an incoming webhook reporting this same state
     * is almost certainly an echo of our own write - skip it.
     */
    public static function wasJustWrittenByUs(string $email, string $channel, string $state, int $withinSeconds = 30): bool
    {
        return self::where('email', strtolower($email))
            ->where('channel', $channel)
            ->where('state', $state)
            ->where('synced_at', '>=', now()->subSeconds($withinSeconds))
            ->exists();
    }
}
