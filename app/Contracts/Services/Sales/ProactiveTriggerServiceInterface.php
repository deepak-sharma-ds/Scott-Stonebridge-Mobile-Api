<?php

declare(strict_types=1);

namespace App\Contracts\Services\Sales;

use App\Models\TriggerRule;

/**
 * Selects and prepares the highest-priority active trigger for a given page
 * + shop combination, with per-session dedupe so the chat does not re-open
 * itself once a customer has dismissed it.
 */
interface ProactiveTriggerServiceInterface
{
    /**
     * Return the highest-priority active TriggerRule that matches the shop and
     * page, or null if no rule applies. Rules with page_type='all' also match.
     */
    public function getTopTriggerForPage(string $pageType, string $shopDomain): ?TriggerRule;

    /**
     * Resolve message-template placeholders ({product_title}, {cart_total},
     * etc.) against the live context payload from the storefront.
     *
     * @param  array<string, mixed>  $context
     */
    public function buildProactiveMessage(TriggerRule $rule, array $context = []): string;

    /**
     * Return false if the trigger has already fired for this session OR if
     * the session_id is empty.
     */
    public function shouldFire(string $sessionId, TriggerRule $rule): bool;

    /**
     * Atomically claim the per-session fire slot. Returns true exactly once
     * per (session, rule) pair within the TTL window; subsequent concurrent
     * calls return false because the Redis SET NX EX (via Cache::add)
     * detects the key already exists.
     *
     * Callers should treat the boolean as the source of truth — emit the
     * trigger only when this returns true. Reading shouldFire() then writing
     * markFired() afterwards leaves a race window where two parallel
     * pageloads can both pass the check.
     */
    public function markFired(string $sessionId, int $ruleId): bool;
}
