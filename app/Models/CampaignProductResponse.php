<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignProductResponse extends Model
{
    protected $fillable = [
        'campaign_product_id',
        'ai_response',
        'model_used',
        'prompt_tokens',
        'completion_tokens',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function campaignProduct(): BelongsTo
    {
        return $this->belongsTo(CampaignProduct::class);
    }
}
