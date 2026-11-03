<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimeSlot extends Model
{
    use HasFactory;

    protected $fillable = ['availability_date_id', 'start_time', 'end_time'];

    public function availabilityDate()
    {
        return $this->belongsTo(AvailabilityDate::class);
    }

    public function scheduledMeetings(): HasMany
    {
        return $this->hasMany(ScheduledMeeting::class);
    }
}
