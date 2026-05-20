<?php

declare(strict_types=1);

namespace App\Jobs\Sales;

use App\Models\AiConversation;
use App\Models\ConversionEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Append a row to conversion_events and, when relevant, update funnel
 * columns on ai_conversations.
 *
 *   - event=order_placed    -> revenue_attributed += revenue
 *                              conversion_type    = 'direct'
 *                              if a prior abandon_recovery_sent event
 *                              exists for the same session, conversion_type
 *                              becomes 'assisted'.
 *   - event=lead_captured   -> lead_captured = true
 *
 * Queue: `analytics` on the Redis connection.
 *
 * Idempotent at the insert level — duplicate dispatches just append
 * another row (rows are immutable, no unique constraint by design so
 * multiple clicks etc. are recorded).
 */
class StoreConversionEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $shopDomain,
        public readonly string $eventType,
        public readonly ?string $productId = null,
        public readonly ?string $orderId = null,
        public readonly ?float $revenue = null,
        public readonly array $metadata = [],
    ) {}

    public function handle(): void
    {
        if ($this->sessionId === '' || $this->shopDomain === '' || $this->eventType === '') {
            return;
        }

        $row = ConversionEvent::create([
            'session_id' => $this->sessionId,
            'shop_domain' => $this->shopDomain,
            'event_type' => $this->eventType,
            'product_id' => $this->productId,
            'order_id' => $this->orderId,
            'revenue' => $this->revenue,
            'metadata_json' => $this->metadata !== [] ? $this->metadata : null,
            'created_at' => now(),
        ]);

        $this->applyConversationSideEffects($row);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('error')->error('StoreConversionEventJob failed', [
            'session_id' => $this->sessionId,
            'event_type' => $this->eventType,
            'error' => $exception->getMessage(),
        ]);
    }

    private function applyConversationSideEffects(ConversionEvent $row): void
    {
        // SELECT … FOR UPDATE serializes concurrent side-effects for the
        // same session. Two parallel order_placed events used to race on
        // revenue_attributed (Eloquent read-then-save lost increments);
        // the explicit lock + atomic UPDATE statement below adds the new
        // revenue in a single DB op, so duplicate dispatches accumulate
        // correctly instead of clobbering each other.
        DB::transaction(function () use ($row): void {
            $conversation = AiConversation::query()
                ->where('session_id', $row->session_id)
                ->lockForUpdate()
                ->first();

            if ($conversation === null) {
                return;
            }

            $updates = [];

            if ($row->event_type === ConversionEvent::EVENT_ORDER_PLACED) {
                $revenue = (float) ($row->revenue ?? 0.0);
                if ($revenue > 0.0) {
                    // DB::raw value built from a hard-cast float — no SQL
                    // injection vector. Atomic add at the engine level.
                    $updates['revenue_attributed'] = DB::raw(
                        'ROUND(COALESCE(revenue_attributed, 0) + '.$revenue.', 2)'
                    );
                }

                // Assisted iff a recovery email landed earlier on the same
                // session. Inside the lock so a recovery event arriving
                // between the read and the write cannot flip the verdict.
                $hadRecovery = ConversionEvent::query()
                    ->forSession($row->session_id)
                    ->ofType(ConversionEvent::EVENT_ABANDON_RECOVERY_SENT)
                    ->where('id', '<', $row->id)
                    ->exists();

                $updates['conversion_type'] = $hadRecovery
                    ? AiConversation::CONVERSION_ASSISTED
                    : AiConversation::CONVERSION_DIRECT;
            }

            if ($row->event_type === ConversionEvent::EVENT_LEAD_CAPTURED && ! $conversation->lead_captured) {
                $updates['lead_captured'] = true;
            }

            if ($updates !== []) {
                AiConversation::query()
                    ->whereKey($conversation->id)
                    ->update($updates);
            }
        });
    }
}
