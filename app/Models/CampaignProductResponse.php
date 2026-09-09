<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignProductResponse extends Model
{
    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'campaign_product_id',
        'source',
        'body',
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

    /**
     * Human-readable source labels for the admin UI.
     *
     * @return array<string,string>
     */
    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_AI => 'AI Generated',
            self::SOURCE_MANUAL => 'Manual',
        ];
    }
}
