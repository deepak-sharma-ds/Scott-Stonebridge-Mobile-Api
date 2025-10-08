<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeSlot extends Model
{
    use HasFactory;

    protected $fillable = ['availability_date_id', 'start_time', 'end_time'];

    public function availabilityDate()
    {
        return $this->belongsTo(AvailabilityDate::class);
    }
}
