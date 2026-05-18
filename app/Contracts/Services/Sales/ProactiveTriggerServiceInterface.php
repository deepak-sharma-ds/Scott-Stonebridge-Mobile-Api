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
     * Set a per-session Redis flag (TTL from config('sales.triggers.session_fired_ttl'))
     * so the same rule is not surfaced again on subsequent page views.
     */
    public function markFired(string $sessionId, int $ruleId): void;
}
