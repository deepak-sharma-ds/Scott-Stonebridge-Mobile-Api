<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KlaviyoCampaignSweep extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SWEEPING = 'sweeping';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'campaign_id',
        'campaign_name',
        'send_time',
        'status',
        'events_cursor',
        'recipients_found',
        'pushes_dispatched',
        'swept_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'send_time' => 'datetime',
            'recipients_found' => 'integer',
            'pushes_dispatched' => 'integer',
            'swept_at' => 'datetime',
        ];
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
        ])->save();
    }
}
