<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'title',
        'description',
        'price',
        'currency',
        'shopify_tag',
        'cover_image',
        'status'
    ];

    public function audios()
    {
        return $this->hasMany(Audio::class)->orderBy('order_index');
    }
}
