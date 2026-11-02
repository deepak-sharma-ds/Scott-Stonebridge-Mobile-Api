<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledMeeting extends Model
{
    use HasFactory;

    protected $table = 'scheduled_meetings';

    protected $fillable = [
        'user_id',
        'order_id',
        'name',
        'email',
        'phone',
        'datetime',
        'meeting_link',
        'event_id',
        'availability_date_id',
        'time_slot_id',
        'status',
    ];

    protected $casts = [
        'datetime' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Accessors & Mutators
    |--------------------------------------------------------------------------
    */

    public function getNameAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strip_tags($value);
        if (preg_match('/^(.+?)\s*(?:Phone|Tel|Mobile):/i', $clean, $matches)) {
            $clean = $matches[1];
        }

        return trim($clean);
    }

    public function setNameAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['name'] = null;

            return;
        }

        $clean = strip_tags($value);
        if (preg_match('/^(.+?)\s*(?:Phone|Tel|Mobile):/i', $clean, $matches)) {
            $clean = $matches[1];
        }

        $this->attributes['name'] = trim($clean);
    }

    public function getPhoneAttribute(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim(strip_tags($value));
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value !== null ? trim(strip_tags($value)) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function availabilityDate()
    {
        return $this->belongsTo(AvailabilityDate::class);
    }

    public function timeSlot()
    {
        return $this->belongsTo(TimeSlot::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'closed');
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('datetime', '>=', now())
            ->orderBy('datetime');
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('datetime', '<', now())
            ->orderByDesc('datetime');
    }

    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with([
            'availabilityDate:id,date',
            'timeSlot:id,start_time,end_time',
        ]);
    }
}
