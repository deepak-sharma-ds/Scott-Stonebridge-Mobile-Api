<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\Sales\ProactiveTriggerServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Sales\GetTriggerRequest;
use App\Http\Requests\Sales\RecordTriggerEventRequest;
use App\Http\Resources\Sales\TriggerResource;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Proactive trigger endpoints.
 *
 * Routes:
 *   GET  /api/v1/ai/triggers/{shop_domain}  -> show
 *   POST /api/v1/ai/triggers/event          -> recordEvent  (Step 2)
 *
 * The show endpoint is hot — it fires on every storefront page load. It must
 * stay cheap (single index hit) and never call Shopify. recordEvent always
 * returns 200 even on failure so client-side analytics cannot break the UX.
 */
class TriggerController extends BaseApiController
{
    public function __construct(
        private readonly ProactiveTriggerServiceInterface $triggers,
        private readonly AnalyticsServiceInterface $analytics,
    ) {}

    public function show(string $shopDomain, GetTriggerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $pageType = (string) $data['page_type'];
        $sessionId = (string) $data['session_id'];

        try {
            $rule = $this->triggers->getTopTriggerForPage($pageType, $shopDomain);
        } catch (Throwable $e) {
            report($e);

            // Fail closed — no trigger surfaced is always a safe default.
            return $this->success('No trigger available.', ['has_trigger' => false]);
        }

        if ($rule === null || ! $this->triggers->shouldFire($sessionId, $rule)) {
            return $this->success('No trigger available.', ['has_trigger' => false]);
        }

        // Storefront context optionally piggybacks on query string for template
        // interpolation (product_title, cart_total, etc.). Strip the validated
        // keys so only context-ish values remain.
        $context = $request->except(['page_type', 'session_id']);
        $message = $this->triggers->buildProactiveMessage($rule, $context);

        // Stash the resolved message on the model in-memory so the Resource
        // can read it without re-running interpolation.
        $rule->setAttribute('resolved_message', $message);

        $this->triggers->markFired($sessionId, (int) $rule->id);

        return $this->success('Trigger matched.', new TriggerResource($rule));
    }

    /**
     * Record that the storefront opened or dismissed a proactive trigger.
     *
     * Always returns 200 — analytics ingestion must not break the UX. The
     * dispatch goes through AnalyticsServiceInterface so it queues onto the
     * `ai` channel today. Step 9 swaps the underlying job for the new
     * StoreConversionEventJob without changing the call site.
     *
     * Allowed event values come from config('sales.triggers.event_types').
     */
    public function recordEvent(RecordTriggerEventRequest $request): JsonResponse
    {
        $data = $request->validated();
        $event = (string) $data['event'];
        $sessionId = (string) $data['session_id'];

        $payload = [
            'event_type' => $event,
            'shop_domain' => (string) $data['shop_domain'],
            'trigger_type' => $data['trigger_type'] ?? null,
        ];

        try {
            $this->analytics->record($event, $sessionId, $payload);
        } catch (Throwable $e) {
            // Swallow — endpoint must remain 200 even when the queue is down.
            report($e);
        }

        return $this->success('Event acknowledged.', ['accepted' => true]);
    }
}
