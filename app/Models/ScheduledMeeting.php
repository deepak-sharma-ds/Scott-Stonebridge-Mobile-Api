<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledMeeting extends Model
{
    use HasFactory;

    protected $table = 'scheduled_meetings'; // Table name
    protected $fillable = ['user_id', 'name', 'email', 'phone', 'datetime', 'meeting_link', 'event_id', 'availability_date_id', 'time_slot_id', 'status'];
    protected $casts = ['datetime' => 'datetime'];
}

