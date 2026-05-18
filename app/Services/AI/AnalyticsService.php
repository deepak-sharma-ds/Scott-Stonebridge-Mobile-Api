<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Jobs\AI\StoreAnalyticsJob;
use App\Jobs\Sales\StoreConversionEventJob;
use App\Models\AiConversation;
use App\Models\ConversionEvent;
use App\Services\Base\BaseService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Thin facade in front of the analytics queue. Every event is dispatched
 * asynchronously so the request path never waits on persistence.
 *
 * Phase 2 / Phase E additions:
 *   - Conversion-meaningful events are ALSO fanned out to
 *     StoreConversionEventJob so the conversion_events table receives
 *     a row. Pure-chat events (intent.detected, etc.) still only land
 *     in the `ai` log channel via StoreAnalyticsJob.
 *   - Five aggregate query methods used by the (future) admin dashboard.
 */
class AnalyticsService extends BaseService implements AnalyticsServiceInterface
{
    /**
     * Map Phase 1 analytics event names -> conversion_events.event_type.
     * Anything not in this map only goes to the log channel.
     *
     * @var array<string, string>
     */
    private const CONVERSION_EVENT_MAP = [
        self::EVENT_SESSION_STARTED => ConversionEvent::EVENT_CHAT_OPENED,
        self::EVENT_MESSAGE_SENT => ConversionEvent::EVENT_MESSAGE_SENT,
        self::EVENT_ESCALATION_TRIGGERED => ConversionEvent::EVENT_ESCALATION_TRIGGERED,
        self::EVENT_SESSION_ENDED => ConversionEvent::EVENT_CHAT_CLOSED,
    ];

    public function record(string $event, string $sessionId, array $payload = []): void
    {
        // Behaviour event log (Phase 1) — unchanged.
        try {
            StoreAnalyticsJob::dispatch($event, $sessionId, $payload)
                ->onConnection((string) config('chatbot.queue.connection', 'redis'))
                ->onQueue((string) config('chatbot.queue.name', 'ai'));
        } catch (Throwable $e) {
            $this->logWarning('Analytics dispatch failed', [
                'event' => $event,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ], 'ai');
        }

        // Conversion funnel fan-out (Phase 2).
        $this->maybeDispatchConversionEvent($event, $sessionId, $payload);
    }

    /**
     * Conversion rate = (sessions w/ order_placed) / (total sessions).
     */
    public function getConversionRate(string $shopDomain, CarbonInterface $from, CarbonInterface $to): float
    {
        $total = AiConversation::query()
            ->where('shop_domain', $shopDomain)
            ->whereBetween('started_at', [$from, $to])
            ->count();
        if ($total === 0) {
            return 0.0;
        }

        $converted = ConversionEvent::query()
            ->where('shop_domain', $shopDomain)
            ->where('event_type', ConversionEvent::EVENT_ORDER_PLACED)
            ->whereBetween('created_at', [$from, $to])
            ->distinct('session_id')
            ->count('session_id');

        return round($converted / $total, 4);
    }

    /**
     * Sum of revenue_attributed for conversations that ended within range.
     */
    public function getRevenueAttributed(string $shopDomain, CarbonInterface $from, CarbonInterface $to): float
    {
        $sum = AiConversation::query()
            ->where('shop_domain', $shopDomain)
            ->whereBetween('ended_at', [$from, $to])
            ->sum('revenue_attributed');

        return round((float) $sum, 2);
    }

    /**
     * (sessions with captured lead) / (total sessions).
     */
    public function getLeadCaptureRate(string $shopDomain, CarbonInterface $from, CarbonInterface $to): float
    {
        $total = AiConversation::query()
            ->where('shop_domain', $shopDomain)
            ->whereBetween('started_at', [$from, $to])
            ->count();
        if ($total === 0) {
            return 0.0;
        }

        $captured = AiConversation::query()
            ->where('shop_domain', $shopDomain)
            ->where('lead_captured', true)
            ->whereBetween('started_at', [$from, $to])
            ->count();

        return round($captured / $total, 4);
    }

    /**
     * Intents that most frequently end in order_placed, ranked desc.
     * Reads ai_messages.intent joined against converted sessions.
     *
     * @return Collection<int, object{intent: string, conversions: int}>
     */
    public function getTopConvertingIntents(string $shopDomain, int $limit = 10): Collection
    {
        return DB::query()
            ->fromSub(
                ConversionEvent::query()
                    ->select('session_id')
                    ->where('shop_domain', $shopDomain)
                    ->where('event_type', ConversionEvent::EVENT_ORDER_PLACED)
                    ->distinct(),
                'converted',
            )
            ->join('ai_messages as m', function ($join) {
                $join->on('m.intent', '!=', DB::raw("''"))
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('ai_conversations as c')
                            ->whereColumn('c.session_id', 'converted.session_id')
                            ->whereColumn('c.id', 'm.conversation_id');
                    });
            })
            ->select('m.intent as intent', DB::raw('COUNT(*) as conversions'))
            ->groupBy('m.intent')
            ->orderByDesc('conversions')
            ->limit($limit)
            ->get();
    }

    /**
     * (sessions where recovery email led to order_placed) / (sessions
     * with abandon_recovery_sent emitted within range).
     */
    public function getAbandonRecoveryRate(string $shopDomain, CarbonInterface $from, CarbonInterface $to): float
    {
        $sent = ConversionEvent::query()
            ->where('shop_domain', $shopDomain)
            ->where('event_type', ConversionEvent::EVENT_ABANDON_RECOVERY_SENT)
            ->whereBetween('created_at', [$from, $to])
            ->distinct('session_id')
            ->pluck('session_id');

        if ($sent->isEmpty()) {
            return 0.0;
        }

        $recovered = ConversionEvent::query()
            ->where('shop_domain', $shopDomain)
            ->where('event_type', ConversionEvent::EVENT_ORDER_PLACED)
            ->whereIn('session_id', $sent)
            ->distinct('session_id')
            ->count('session_id');

        return round($recovered / $sent->count(), 4);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function maybeDispatchConversionEvent(string $event, string $sessionId, array $payload): void
    {
        // Phase 1 named events go through the static map.
        $resolved = self::CONVERSION_EVENT_MAP[$event] ?? null;

        // Phase 2 callers pass raw conversion_event names directly (e.g.
        // 'lead_captured', 'abandon_recovery_sent', 'trigger_opened') — if
        // the event matches an allowed conversion type, fan it out.
        if ($resolved === null) {
            $allowed = (array) config('sales.analytics.event_types', []);
            if (in_array($event, $allowed, true)) {
                $resolved = $event;
            }
        }

        if ($resolved === null) {
            return;
        }

        $shopDomain = (string) ($payload['shop_domain'] ?? '');
        if ($shopDomain === '' || $sessionId === '') {
            return;
        }

        try {
            StoreConversionEventJob::dispatch(
                sessionId: $sessionId,
                shopDomain: $shopDomain,
                eventType: $resolved,
                productId: isset($payload['product_id']) ? (string) $payload['product_id'] : null,
                orderId: isset($payload['order_id']) ? (string) $payload['order_id'] : null,
                revenue: isset($payload['revenue']) ? (float) $payload['revenue'] : null,
                metadata: $payload,
            )
                ->onConnection((string) config('sales.queue.connection', 'redis'))
                ->onQueue((string) config('sales.queue.analytics', 'analytics'));
        } catch (Throwable $e) {
            $this->logWarning('Conversion event fan-out failed', [
                'event' => $event,
                'resolved' => $resolved,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ], 'ai');
        }
    }
}
