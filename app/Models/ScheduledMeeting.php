<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ScheduledMeeting extends Model
{
    use HasFactory;

    protected $table = 'scheduled_meetings';
    
    protected $fillable = [
        'user_id', 
        'name', 
        'email', 
        'phone', 
        'datetime', 
        'meeting_link', 
        'event_id', 
        'availability_date_id', 
        'time_slot_id', 
        'status'
    ];
    
    protected $casts = [
        'datetime' => 'datetime'
    ];

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
            'timeSlot:id,start_time,end_time'
        ]);
    }
}
