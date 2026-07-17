<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CampaignProduct extends Model
{
    protected $fillable = [
        'marketing_campaign_id',
        'shopify_product_id',
        'product_title',
        'prompt_template',
        'email_subject',
        'model',
        'max_tokens',
    ];

    protected function casts(): array
    {
        return [
            'shopify_product_id' => 'integer',
            'max_tokens' => 'integer',
        ];
    }

    public function marketingCampaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(CampaignProductResponse::class);
    }
}
