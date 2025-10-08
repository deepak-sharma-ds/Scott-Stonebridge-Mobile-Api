<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AvailabilityDate extends Model
{
    use HasFactory;

    protected $fillable = ['date', 'user_id'];
    protected $casts = [
        'date' => 'date',
    ];

    public function timeSlots()
    {
        return $this->hasMany(TimeSlot::class);
    }
    
}
