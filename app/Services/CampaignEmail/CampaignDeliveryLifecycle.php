<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Jobs\Campaign\MarkCampaignOrderFulfilledJob;
use App\Jobs\Campaign\NotifyCampaignFailureJob;
use App\Jobs\Campaign\SendCampaignEmailJob;
use App\Models\CampaignDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The single place that moves a CampaignDelivery between states. Mirrors
 * EmailReadingDeliveryLifecycle (ADR 0001: the two flows share no code path).
 *
 * The delivery row (status + scheduled_at) is the source of truth for when and
 * whether a campaign email goes out. A scheduler calls dispatchDue(), which
 * atomically claims each due delivery (`pending -> sending`) and dispatches an
 * immediate send job carrying the claim token. There is no generation step:
 * the Campaign Response is pre-generated per Campaign Product (ADR 0003) and
 * read live at send time.
 *
 * Every transition is ONE conditional UPDATE (`WHERE status IN (...)`), so
 * concurrent actors can never both win. A `sending` delivery is frozen: admin
 * actions are rejected until the send finishes (or is recovered).
 */
class CampaignDeliveryLifecycle
{
    /** States in which the schedule may still be edited. */
    private const SCHEDULE_EDITABLE = [
        CampaignDelivery::STATUS_PENDING,
        CampaignDelivery::STATUS_FAILED,
        CampaignDelivery::STATUS_CANCELLED,
    ];

    /**
     * Claim and dispatch every due delivery (scheduler entry point).
     *
     * @return int number of deliveries claimed and dispatched
     */
    public function dispatchDue(): int
    {
        $ids = CampaignDelivery::query()
            ->where('status', CampaignDelivery::STATUS_PENDING)
            ->whereNotNull('campaign_product_id')
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', Carbon::now()))
            ->orderBy('scheduled_at')
            ->limit(max(1, (int) config('campaign_email.dispatch.batch_size', 100)))
            ->pluck('id');

        $dispatched = 0;

        foreach ($ids as $id) {
            $token = $this->claim((int) $id, [CampaignDelivery::STATUS_PENDING], dueOnly: true);
            if ($token === null) {
                continue;
            }

            if ($this->dispatchSend((int) $id, $token)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Atomically move a delivery to `sending`, returning the claim token — or
     * null when it was not in one of $fromStatuses (someone else got there
     * first, it was cancelled, rescheduled into the future, etc.).
     *
     * `sent_at` is cleared so the send job's "mail already went out" check
     * cannot suppress a deliberate resend.
     *
     * @param  array<int,string>  $fromStatuses
     */
    public function claim(int $deliveryId, array $fromStatuses, bool $dueOnly = false): ?string
    {
        $token = (string) Str::uuid();

        $query = CampaignDelivery::query()
            ->whereKey($deliveryId)
            ->whereIn('status', $fromStatuses);

        if ($dueOnly) {
            $query->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', Carbon::now()));
        }

        $claimed = $query->update([
            'status' => CampaignDelivery::STATUS_SENDING,
            'claim_token' => $token,
            'claimed_at' => Carbon::now(),
            'sent_at' => null,
            'sent_manually' => false,
            'error_message' => null,
        ]);

        return $claimed === 1 ? $token : null;
    }

    /**
     * @throws RuntimeException when the delivery is currently being sent
     */
    public function assertNotSending(CampaignDelivery $delivery): void
    {
        $delivery->refresh();

        if ($delivery->status === CampaignDelivery::STATUS_SENDING) {
            throw new RuntimeException('This delivery is being sent right now. Try again in a minute.');
        }
    }

    /**
     * Send (or re-send) a delivery now. Requires a resolved Campaign Product
     * with an existing Campaign Response — there is no live-generation
     * fallback, so an unresolved pairing must be re-paired first.
     *
     * @throws RuntimeException when the delivery cannot be sent from its current state
     */
    public function sendNow(CampaignDelivery $delivery, bool $resend = false): void
    {
        $this->assertNotSending($delivery);

        $this->assertResponseResolved($delivery);

        if ($delivery->status === CampaignDelivery::STATUS_CANCELLED) {
            throw new RuntimeException('This delivery is cancelled. Reactivate it before sending.');
        }

        if ($delivery->status === CampaignDelivery::STATUS_SENT && ! $resend) {
            throw new RuntimeException('This delivery has already been sent. Use re-send to send it again.');
        }

        $from = [CampaignDelivery::STATUS_PENDING, CampaignDelivery::STATUS_FAILED];
        if ($resend) {
            $from[] = CampaignDelivery::STATUS_SENT;
        }

        $token = $this->claim($delivery->id, $from);
        if ($token === null) {
            throw new RuntimeException('This delivery changed state just now (it may be sending or cancelled). Refresh and try again.');
        }

        $this->dispatchSend($delivery->id, $token, rethrow: true);
        $delivery->refresh();
    }

    /**
     * Cancel a not-yet-sent delivery. `failed` is included so an admin can
     * write off an unrecoverable Attribution Failure. `sent` is final.
     *
     * @return bool false when it was already sent/cancelled (nothing changed)
     *
     * @throws RuntimeException when the delivery is being sent right now
     */
    public function cancel(CampaignDelivery $delivery): bool
    {
        $this->assertNotSending($delivery);

        $cancelled = CampaignDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', [
                CampaignDelivery::STATUS_PENDING,
                CampaignDelivery::STATUS_FAILED,
            ])
            ->update(['status' => CampaignDelivery::STATUS_CANCELLED]);

        $delivery->refresh();

        return $cancelled === 1;
    }

    /**
     * Webhook cancel: cancel unsent deliveries of an order, optionally
     * limited to refunded line items. Attribution failures (`failed`) are left
     * for an admin to resolve.
     *
     * @param  array<int,int>|null  $lineItemIds  null = whole order
     */
    public function cancelForOrder(int $orderId, ?array $lineItemIds = null): int
    {
        $query = CampaignDelivery::query()
            ->where('shopify_order_id', $orderId)
            ->whereIn('status', [
                CampaignDelivery::STATUS_PENDING,
                CampaignDelivery::STATUS_GENERATED,
            ]);

        if ($lineItemIds !== null) {
            $query->whereIn('shopify_line_item_id', $lineItemIds);
        }

        return $query->update(['status' => CampaignDelivery::STATUS_CANCELLED]);
    }

    /**
     * Bring a cancelled or failed delivery back to `pending`; the dispatcher
     * sends it when due. Requires a resolved Campaign Response (re-pair an
     * Attribution Failure first, ADR 0005).
     *
     * @return string|null the new status, or null when not reactivatable
     *
     * @throws RuntimeException when no response is resolved or the delivery is sending
     */
    public function reactivate(CampaignDelivery $delivery): ?string
    {
        $this->assertNotSending($delivery);

        $this->assertResponseResolved($delivery);

        $updated = CampaignDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', [
                CampaignDelivery::STATUS_CANCELLED,
                CampaignDelivery::STATUS_FAILED,
            ])
            ->update([
                'status' => CampaignDelivery::STATUS_PENDING,
                'error_message' => null,
                'claim_token' => null,
                'claimed_at' => null,
                'release_count' => 0,
            ]);

        $delivery->refresh();

        return $updated === 1 ? CampaignDelivery::STATUS_PENDING : null;
    }

    /**
     * Change when a delivery is due. Allowed only before it is claimed.
     *
     * @throws RuntimeException when the delivery is sending or already sent
     */
    public function reschedule(CampaignDelivery $delivery, ?Carbon $scheduledAt, ?Carbon $expeditedAt): void
    {
        $this->assertNotSending($delivery);

        $updated = CampaignDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', self::SCHEDULE_EDITABLE)
            ->update([
                'scheduled_at' => $scheduledAt,
                'expedited_at' => $expeditedAt,
            ]);

        $delivery->refresh();

        if ($updated !== 1) {
            throw new RuntimeException('A delivery that has already been sent cannot be rescheduled. Use re-send instead.');
        }
    }

    /**
     * Same-day upgrade: pull every still-unclaimed, not-yet-expedited
     * delivery of the order forward.
     */
    public function expediteOrder(int $orderId, Carbon $at): int
    {
        return CampaignDelivery::query()
            ->where('shopify_order_id', $orderId)
            ->whereIn('status', [
                CampaignDelivery::STATUS_PENDING,
                CampaignDelivery::STATUS_GENERATED,
            ])
            ->whereNull('expedited_at')
            ->update([
                'scheduled_at' => $at,
                'expedited_at' => Carbon::now(),
            ]);
    }

    /**
     * Close a delivery the admin already emailed by hand. No email is sent;
     * the row is stamped `sent` + `sent_manually`. Fulfilment is queued unless
     * the admin opted out.
     *
     * @throws RuntimeException when the delivery cannot be closed from its current state
     */
    public function markSentManually(CampaignDelivery $delivery, bool $fulfil = true): void
    {
        $this->assertNotSending($delivery);

        $updated = CampaignDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', [
                CampaignDelivery::STATUS_PENDING,
                CampaignDelivery::STATUS_FAILED,
            ])
            ->update([
                'status' => CampaignDelivery::STATUS_SENT,
                'sent_at' => Carbon::now(),
                'sent_manually' => true,
                'error_message' => null,
                'claim_token' => null,
                'claimed_at' => null,
            ]);

        $delivery->refresh();

        if ($updated !== 1) {
            throw new RuntimeException('Only pending or failed deliveries can be marked as sent manually. Reactivate a cancelled delivery first.');
        }

        if ($fulfil) {
            $this->dispatchFulfillment($delivery->id);
        }
    }

    /**
     * Re-queue a failed Shopify fulfilment for an already-sent delivery.
     *
     * @throws RuntimeException when there is nothing to fulfil
     */
    public function retryFulfillment(CampaignDelivery $delivery): void
    {
        $delivery->refresh();

        if (! $delivery->needsFulfillment()) {
            throw new RuntimeException('Only a sent, unfulfilled delivery can have its fulfilment retried.');
        }

        $this->dispatchFulfillment($delivery->id);
    }

    /**
     * Whether a delivery may be hard-deleted: never while sending, never once
     * sent (the audit trail of a real send is kept).
     */
    public function canDelete(CampaignDelivery $delivery): bool
    {
        $delivery->refresh();

        return ! in_array($delivery->status, [
            CampaignDelivery::STATUS_SENDING,
            CampaignDelivery::STATUS_SENT,
        ], true);
    }

    /**
     * Record that the mail left the building, under the owning claim.
     */
    public function recordMailSent(int $deliveryId, string $claimToken): bool
    {
        return CampaignDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', CampaignDelivery::STATUS_SENDING)
            ->where('claim_token', $claimToken)
            ->update(['sent_at' => Carbon::now()]) === 1;
    }

    /**
     * Finalize a send (`sending -> sent`) under the owning claim and queue
     * the Shopify fulfilment.
     */
    public function markSent(int $deliveryId, string $claimToken): bool
    {
        $updated = CampaignDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', CampaignDelivery::STATUS_SENDING)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => CampaignDelivery::STATUS_SENT,
                'claim_token' => null,
                'claimed_at' => null,
                'error_message' => null,
            ]);

        if ($updated === 1) {
            $this->dispatchFulfillment($deliveryId);
        }

        return $updated === 1;
    }

    /**
     * Permanent send failure under the owning claim. Returns false when the
     * claim is no longer ours — callers must then not alert.
     */
    public function markSendFailed(int $deliveryId, string $claimToken, string $message): bool
    {
        return CampaignDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', CampaignDelivery::STATUS_SENDING)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => CampaignDelivery::STATUS_FAILED,
                'error_message' => $message,
                'claim_token' => null,
                'claimed_at' => null,
            ]) === 1;
    }

    /**
     * Recover deliveries stuck in `sending` (worker died, job lost).
     *
     * - mail already recorded as sent -> finalize as `sent` (+ fulfilment)
     * - first stall                   -> release back to `pending`, claim
     *                                    cleared (a straggling old job no-ops)
     * - stalled again (max releases)  -> mark `failed` and notify an admin
     *
     * @return array{finalized:int,released:int,failed:int}
     */
    public function recoverStuck(): array
    {
        $cutoff = Carbon::now()->subMinutes(max(1, (int) config('campaign_email.dispatch.stuck_after_minutes', 15)));
        $maxReleases = max(0, (int) config('campaign_email.dispatch.max_releases', 1));

        $stuck = CampaignDelivery::query()
            ->where('status', CampaignDelivery::STATUS_SENDING)
            ->where('claimed_at', '<', $cutoff)
            ->get(['id', 'sent_at', 'release_count']);

        $result = ['finalized' => 0, 'released' => 0, 'failed' => 0];

        foreach ($stuck as $row) {
            $base = CampaignDelivery::query()
                ->whereKey($row->id)
                ->where('status', CampaignDelivery::STATUS_SENDING)
                ->where('claimed_at', '<', $cutoff);

            if ($row->sent_at !== null) {
                $updated = (clone $base)->whereNotNull('sent_at')->update([
                    'status' => CampaignDelivery::STATUS_SENT,
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);

                if ($updated === 1) {
                    $result['finalized']++;
                    $this->dispatchFulfillment((int) $row->id);
                }

                continue;
            }

            if ((int) $row->release_count >= $maxReleases) {
                $message = 'Send stalled '.($row->release_count + 1).' times without completing; needs manual review.';
                $updated = (clone $base)->whereNull('sent_at')->update([
                    'status' => CampaignDelivery::STATUS_FAILED,
                    'error_message' => $message,
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);

                if ($updated === 1) {
                    $result['failed']++;
                    NotifyCampaignFailureJob::dispatch((int) $row->id, $message)
                        ->onConnection(config('campaign_email.queue.connection'))
                        ->onQueue(config('campaign_email.queue.mail'));
                }

                continue;
            }

            $updated = (clone $base)->whereNull('sent_at')->update([
                'status' => CampaignDelivery::STATUS_PENDING,
                'claim_token' => null,
                'claimed_at' => null,
                'release_count' => (int) $row->release_count + 1,
            ]);

            if ($updated === 1) {
                $result['released']++;
                Log::channel('shopify_webhooks')->warning('Stuck campaign delivery released for re-dispatch', [
                    'delivery_id' => $row->id,
                ]);
            }
        }

        return $result;
    }

    /**
     * Count of due deliveries the dispatcher has not claimed for a while.
     * Feeds the health check: non-zero means the scheduler is not running.
     */
    public function overdueCount(int $graceMinutes = 10): int
    {
        return CampaignDelivery::query()
            ->where('status', CampaignDelivery::STATUS_PENDING)
            ->whereNotNull('campaign_product_id')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', Carbon::now()->subMinutes($graceMinutes))
            ->count();
    }

    /**
     * @throws RuntimeException when no Campaign Response is resolvable
     */
    private function assertResponseResolved(CampaignDelivery $delivery): void
    {
        $delivery->loadMissing('campaignProduct.response');

        if (! $delivery->campaignProduct?->response) {
            throw new RuntimeException('No campaign response resolved for this delivery yet. Re-pair it to a Campaign Product with a generated response first.');
        }
    }

    private function dispatchSend(int $deliveryId, string $claimToken, bool $rethrow = false): bool
    {
        try {
            SendCampaignEmailJob::dispatch($deliveryId, $claimToken)
                ->onConnection(config('campaign_email.queue.connection'))
                ->onQueue(config('campaign_email.queue.mail'));

            return true;
        } catch (Throwable $e) {
            // The claim stays; recoverStuck() releases it if no job ever runs.
            Log::channel('shopify_webhooks')->error('Failed to dispatch campaign send job', [
                'delivery_id' => $deliveryId,
                'error' => $e->getMessage(),
            ]);

            if ($rethrow) {
                throw $e;
            }

            return false;
        }
    }

    private function dispatchFulfillment(int $deliveryId): void
    {
        if (! (bool) config('campaign_email.fulfillment.enabled', true)) {
            return;
        }

        MarkCampaignOrderFulfilledJob::dispatch($deliveryId)
            ->onConnection(config('campaign_email.queue.connection'))
            ->onQueue(config('campaign_email.queue.mail'));
    }
}
