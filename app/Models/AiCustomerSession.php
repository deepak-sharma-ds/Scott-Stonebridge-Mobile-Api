<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * AiCustomerSession — short-lived Shopify Customer Account access token bound
 * to a chat session. Token is stored encrypted at rest via Laravel's
 * `encrypted` cast.
 *
 * @property string $id
 * @property string $session_id
 * @property string $customer_access_token
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiCustomerSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id',
        'customer_access_token',
        'refresh_token',
        'expires_at',
        'refresh_token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<AiCustomerSession>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
