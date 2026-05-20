<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StoreKnowledgeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * StoreKnowledge — per-shop snippet index used by the prompt builder.
 *
 * @property int $id
 * @property string $shop_domain
 * @property string $content_type
 * @property string $title
 * @property string|null $handle
 * @property string $summary
 * @property string $raw_content
 * @property Carbon $last_synced_at
 * @property Carbon|null $shopify_updated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class StoreKnowledge extends Model
{
    /** @use HasFactory<StoreKnowledgeFactory> */
    use HasFactory;

    protected $table = 'store_knowledge';

    public const TYPE_PAGE = 'page';

    public const TYPE_POLICY = 'policy';

    public const TYPE_BLOG = 'blog';

    public const TYPE_FAQ = 'faq';

    public const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'shop_domain',
        'content_type',
        'title',
        'handle',
        'summary',
        'raw_content',
        'last_synced_at',
        'shopify_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<StoreKnowledge>  $query
     * @param  list<string>  $types
     */
    public function scopeForTypes(Builder $query, array $types): Builder
    {
        return $query->whereIn('content_type', $types);
    }

    /**
     * @param  Builder<StoreKnowledge>  $query
     */
    public function scopeForShop(Builder $query, string $shopDomain): Builder
    {
        return $query->where('shop_domain', $shopDomain);
    }
}
