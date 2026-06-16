<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailReadingProduct extends Model
{
    protected $fillable = [
        'shopify_product_id',
        'name',
        'slug',
        'questions_schema',
        'prompt_template',
        'email_subject',
        'email_view',
        'model',
        'max_tokens',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'shopify_product_id' => 'integer',
            'questions_schema' => 'array',
            'max_tokens' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EmailReadingDelivery::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
