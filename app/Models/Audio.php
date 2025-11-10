<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Audio extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'package_id',
        'title',
        'file_path',
        'duration_seconds',
        'order_index',
        'status'
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}
