<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TriggerRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TriggerRule — proactive chat opening rules per shop + page.
 *
 * Pure rule storage. Selection + dedupe lives in ProactiveTriggerService.
 *
 * @property int $id
 * @property string $shop_domain
 * @property string $page_type
 * @property string $trigger_type
 * @property int|null $trigger_value
 * @property string $message_template
 * @property bool $is_active
 * @property int $priority
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TriggerRule extends Model
{
    /** @use HasFactory<TriggerRuleFactory> */
    use HasFactory;

    public const PAGE_HOME = 'home';

    public const PAGE_PRODUCT = 'product';

    public const PAGE_CART = 'cart';

    public const PAGE_COLLECTION = 'collection';

    public const PAGE_ALL = 'all';

    public const TYPE_EXIT_INTENT = 'exit_intent';

    public const TYPE_TIME_ON_PAGE = 'time_on_page';

    public const TYPE_SCROLL_DEPTH = 'scroll_depth';

    public const TYPE_CART_ABANDONMENT = 'cart_abandonment';

    protected $fillable = [
        'shop_domain',
        'page_type',
        'trigger_type',
        'trigger_value',
        'message_template',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'trigger_value' => 'integer',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /**
     * @param  Builder<TriggerRule>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rules that target the given page_type OR are scoped to 'all'.
     *
     * @param  Builder<TriggerRule>  $query
     */
    public function scopeForPage(Builder $query, string $pageType): Builder
    {
        return $query->whereIn('page_type', [$pageType, self::PAGE_ALL]);
    }

    /**
     * @param  Builder<TriggerRule>  $query
     */
    public function scopeForShop(Builder $query, string $shopDomain): Builder
    {
        return $query->where('shop_domain', $shopDomain);
    }
}
