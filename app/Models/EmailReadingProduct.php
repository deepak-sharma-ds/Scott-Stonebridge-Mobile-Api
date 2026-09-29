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
        'header_image',
        'email_content',
        'email_footer',
        'questions_schema',
        'prompt_template',
        'email_subject',
        'email_view',
        'model',
        'max_tokens',
        'is_active',
        'is_automation_enabled',
    ];

    protected function casts(): array
    {
        return [
            'shopify_product_id' => 'integer',
            'questions_schema' => 'array',
            'max_tokens' => 'integer',
            'is_active' => 'boolean',
            'is_automation_enabled' => 'boolean',
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

    public function scopeAutomationEnabled($query)
    {
        return $query->where('is_automation_enabled', true);
    }

    /**
     * Whether the checkout-facing questions API should return data for this
     * product: active, full stop. An empty `questions_schema` is a valid,
     * permanent state (some readings need no personalization) — it still
     * counts as registered, just with an empty `questions` list in the API
     * response. Only a missing row or `is_active = false` count as
     * unregistered (generic 404 to the caller).
     */
    public function isCheckoutRegistered(): bool
    {
        return (bool) $this->is_active;
    }
}
