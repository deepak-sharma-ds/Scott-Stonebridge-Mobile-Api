<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Contracts\Services\Sales\ProactiveTriggerServiceInterface;
use App\Models\TriggerRule;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;

/**
 * Proactive trigger orchestration. Reads candidate rules from the database,
 * picks the highest-priority active match, applies per-session dedupe via a
 * Redis flag, and interpolates message templates against the live storefront
 * context payload.
 *
 * No Shopify calls are made here — templates either resolve from the inline
 * context array, or the unresolved placeholder is left untouched so the
 * frontend has a deterministic string to render.
 */
class ProactiveTriggerService extends BaseService implements ProactiveTriggerServiceInterface
{
    public function getTopTriggerForPage(string $pageType, string $shopDomain): ?TriggerRule
    {
        if ($shopDomain === '' || $pageType === '') {
            return null;
        }

        return TriggerRule::query()
            ->forShop($shopDomain)
            ->forPage($pageType)
            ->active()
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    public function buildProactiveMessage(TriggerRule $rule, array $context = []): string
    {
        $template = (string) $rule->message_template;

        // Flatten nested context (e.g. {'product': {'title': 'X'}}) into
        // {product_title} placeholders. Two levels are plenty for chat use.
        $flat = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $flat[(string) $key] = $value === null ? '' : (string) $value;

                continue;
            }
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (is_scalar($subValue) || $subValue === null) {
                        $flat[$key.'_'.$subKey] = $subValue === null ? '' : (string) $subValue;
                    }
                }
            }
        }

        return preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            static fn (array $m): string => array_key_exists($m[1], $flat) ? $flat[$m[1]] : $m[0],
            $template,
        ) ?? $template;
    }

    public function shouldFire(string $sessionId, TriggerRule $rule): bool
    {
        if ($sessionId === '') {
            return false;
        }

        return ! Cache::has($this->firedKey($sessionId, (int) $rule->id));
    }

    public function markFired(string $sessionId, int $ruleId): bool
    {
        if ($sessionId === '' || $ruleId <= 0) {
            return false;
        }

        $ttl = (int) config('sales.triggers.session_fired_ttl', 86400);

        // Cache::add() is SET NX EX on Redis. Returns true only for the
        // first writer in the TTL window — every other concurrent caller
        // gets false. Replaces the previous Cache::put which silently
        // overwrote, leaving a window for duplicate fires.
        return Cache::add($this->firedKey($sessionId, $ruleId), 1, $ttl);
    }

    private function firedKey(string $sessionId, int $ruleId): string
    {
        return sprintf('ai:trigger:fired:%s:%d', $sessionId, $ruleId);
    }
}
