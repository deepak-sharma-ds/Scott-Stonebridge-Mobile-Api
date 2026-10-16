<?php

declare(strict_types=1);

namespace App\Services\EmailReading;

use App\Jobs\EmailReading\MarkReadingOrderFulfilledJob;
use App\Jobs\EmailReading\NotifyReadingFailureJob;
use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Jobs\EmailReading\SendEmailReadingJob;
use App\Models\EmailReadingDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The single place that moves an EmailReadingDelivery between states.
 *
 * The delivery row (status + scheduled_at) is the source of truth for when and
 * whether a reading email goes out. There are no long-delayed queue jobs to
 * keep in sync: a scheduler calls dispatchDue(), which atomically claims each
 * due delivery (`generated -> sending`) and dispatches an immediate send job
 * carrying the claim token. Reschedule, send now, cancel, reactivate, resend,
 * mark-sent-manually, expedite and webhook-cancel are all plain, status-guarded
 * row updates, so there is never a queued job that can go stale.
 *
 * Every transition is ONE conditional UPDATE (`WHERE status IN (...)`), so
 * concurrent actors (admin click, webhook, dispatcher, worker) can never both
 * win the same transition. A `sending` delivery is frozen: admin actions are
 * rejected until the send finishes (or is recovered).
 */
class EmailReadingDeliveryLifecycle
{
    /** States in which the schedule may still be edited. */
    private const SCHEDULE_EDITABLE = [
        EmailReadingDelivery::STATUS_PENDING,
        EmailReadingDelivery::STATUS_GENERATED,
        EmailReadingDelivery::STATUS_FAILED,
        EmailReadingDelivery::STATUS_CANCELLED,
        EmailReadingDelivery::STATUS_COMPLIANCE_HOLD,
    ];

    public function __construct(
        private readonly EmailReadingGenerationService $generation
    ) {}

    /**
     * Claim and dispatch every due delivery (scheduler entry point).
     *
     * @return int number of deliveries claimed and dispatched
     */
    public function dispatchDue(): int
    {
        $ids = EmailReadingDelivery::query()
            ->where('status', EmailReadingDelivery::STATUS_GENERATED)
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', Carbon::now()))
            ->orderBy('scheduled_at')
            ->limit(max(1, (int) config('email_reading.dispatch.batch_size', 100)))
            ->pluck('id');

        $dispatched = 0;

        foreach ($ids as $id) {
            $token = $this->claim((int) $id, [EmailReadingDelivery::STATUS_GENERATED], dueOnly: true);
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

        $query = EmailReadingDelivery::query()
            ->whereKey($deliveryId)
            ->whereIn('status', $fromStatuses);

        if ($dueOnly) {
            $query->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', Carbon::now()));
        }

        $claimed = $query->update([
            'status' => EmailReadingDelivery::STATUS_SENDING,
            'claim_token' => $token,
            'claimed_at' => Carbon::now(),
            'sent_at' => null,
            'sent_manually' => false,
            'error_message' => null,
        ]);

        return $claimed === 1 ? $token : null;
    }

    /**
     * Throw when the delivery is currently being sent. Admin actions call this
     * first so what the send job reads is exactly what the admin last saved.
     *
     * @throws RuntimeException
     */
    public function assertNotSending(EmailReadingDelivery $delivery): void
    {
        $delivery->refresh();

        if ($delivery->status === EmailReadingDelivery::STATUS_SENDING) {
            throw new RuntimeException('This reading is being sent right now. Try again in a minute.');
        }
    }

    /**
     * Send a reading now (or re-send an already-sent one). A reading with no
     * response yet is generated synchronously first; any failure there leaves
     * the delivery untouched. Then it is claimed and dispatched immediately.
     *
     * @throws RuntimeException when the delivery cannot be sent from its current state
     */
    public function sendNow(EmailReadingDelivery $delivery, bool $resend = false): void
    {
        $this->assertNotSending($delivery);

        if ($delivery->status === EmailReadingDelivery::STATUS_COMPLIANCE_HOLD) {
            throw new RuntimeException('This reading is on compliance hold ('.$delivery->compliance_flagged_reason.'). Approve it, cancel it or mark it as sent manually first.');
        }

        if ($delivery->status === EmailReadingDelivery::STATUS_CANCELLED) {
            throw new RuntimeException('This reading is cancelled. Reactivate it before sending.');
        }

        if ($delivery->status === EmailReadingDelivery::STATUS_SENT && ! $resend) {
            throw new RuntimeException('This reading has already been sent. Use re-send to send it again.');
        }

        if (trim((string) $delivery->ai_response) === '') {
            if (! $this->generation->generate($delivery)) {
                $delivery->refresh();
                if ($delivery->status === EmailReadingDelivery::STATUS_COMPLIANCE_HOLD) {
                    throw new RuntimeException('This reading was put on compliance hold: '.$delivery->compliance_flagged_reason);
                }
                if ($delivery->status === EmailReadingDelivery::STATUS_FAILED && filled($delivery->error_message)) {
                    throw new RuntimeException($delivery->error_message);
                }

                throw new RuntimeException('The reading changed state while it was being generated. Refresh and try again.');
            }
            $delivery->refresh();
        }

        $from = [
            EmailReadingDelivery::STATUS_PENDING,
            EmailReadingDelivery::STATUS_GENERATED,
            EmailReadingDelivery::STATUS_FAILED,
        ];
        if ($resend) {
            $from[] = EmailReadingDelivery::STATUS_SENT;
        }

        $token = $this->claim($delivery->id, $from);
        if ($token === null) {
            throw new RuntimeException('This reading changed state just now (it may be sending or cancelled). Refresh and try again.');
        }

        $this->dispatchSend($delivery->id, $token, rethrow: true);
        $delivery->refresh();
    }

    /**
     * Cancel a not-yet-sent reading. `sent` is final; `sending` is frozen.
     *
     * @return bool false when it was already sent/cancelled (nothing changed)
     *
     * @throws RuntimeException when the delivery is being sent right now
     */
    public function cancel(EmailReadingDelivery $delivery): bool
    {
        $this->assertNotSending($delivery);

        $cancelled = EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_PENDING,
                EmailReadingDelivery::STATUS_GENERATED,
                EmailReadingDelivery::STATUS_FAILED,
                EmailReadingDelivery::STATUS_COMPLIANCE_HOLD,
            ])
            ->update(['status' => EmailReadingDelivery::STATUS_CANCELLED]);

        $delivery->refresh();

        return $cancelled === 1;
    }

    /**
     * Webhook cancel: cancel unsent (pending/generated) deliveries of an
     * order, optionally limited to refunded line items.
     *
     * @param  array<int,int>|null  $lineItemIds  null = whole order
     */
    public function cancelForOrder(int $orderId, ?array $lineItemIds = null): int
    {
        $query = EmailReadingDelivery::query()
            ->where('shopify_order_id', $orderId)
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_PENDING,
                EmailReadingDelivery::STATUS_GENERATED,
                EmailReadingDelivery::STATUS_COMPLIANCE_HOLD,
            ]);

        if ($lineItemIds !== null) {
            $query->whereIn('shopify_line_item_id', $lineItemIds);
        }

        return $query->update(['status' => EmailReadingDelivery::STATUS_CANCELLED]);
    }

    /**
     * Bring a cancelled or failed delivery back. With a stored response it
     * becomes `generated` (the dispatcher sends it when due); without one it
     * goes back to `pending` and generation is re-queued.
     *
     * @return string|null the new status, or null when not reactivatable
     *
     * @throws RuntimeException when the delivery is being sent right now
     */
    public function reactivate(EmailReadingDelivery $delivery): ?string
    {
        $this->assertNotSending($delivery);

        $target = trim((string) $delivery->ai_response) !== ''
            ? EmailReadingDelivery::STATUS_GENERATED
            : EmailReadingDelivery::STATUS_PENDING;

        $updated = EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_CANCELLED,
                EmailReadingDelivery::STATUS_FAILED,
            ])
            ->update([
                'status' => $target,
                'error_message' => null,
                'claim_token' => null,
                'claimed_at' => null,
                'release_count' => 0,
            ]);

        if ($updated !== 1) {
            return null;
        }

        if ($target === EmailReadingDelivery::STATUS_PENDING) {
            ProcessEmailReadingOrderJob::dispatch($delivery->id)
                ->onConnection(config('email_reading.queue.connection'))
                ->onQueue(config('email_reading.queue.process'));
        }

        $delivery->refresh();

        return $target;
    }

    /**
     * Admin sign-off on a compliance hold: stamps the review so the gate does
     * not re-hold the delivery, returns it to `pending` and re-queues
     * generation. The flagged reason is kept for the audit trail.
     *
     * @return bool false when the delivery is not on compliance hold
     *
     * @throws RuntimeException when the delivery is being sent right now
     */
    public function approveComplianceHold(EmailReadingDelivery $delivery): bool
    {
        $this->assertNotSending($delivery);

        $updated = EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', EmailReadingDelivery::STATUS_COMPLIANCE_HOLD)
            ->update([
                'status' => EmailReadingDelivery::STATUS_PENDING,
                'compliance_reviewed_at' => Carbon::now(),
                'error_message' => null,
            ]);

        if ($updated !== 1) {
            return false;
        }

        ProcessEmailReadingOrderJob::dispatch($delivery->id)
            ->onConnection(config('email_reading.queue.connection'))
            ->onQueue(config('email_reading.queue.process'));

        $delivery->refresh();

        return true;
    }

    /**
     * Change when a delivery is due. Allowed only before it is claimed; once
     * `sending`/`sent` the time can no longer influence anything.
     *
     * @throws RuntimeException when the delivery is sending or already sent
     */
    public function reschedule(EmailReadingDelivery $delivery, ?Carbon $scheduledAt, ?Carbon $expeditedAt): void
    {
        $this->assertNotSending($delivery);

        $updated = EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', self::SCHEDULE_EDITABLE)
            ->update([
                'scheduled_at' => $scheduledAt,
                'expedited_at' => $expeditedAt,
            ]);

        $delivery->refresh();

        if ($updated !== 1) {
            throw new RuntimeException('A reading that has already been sent cannot be rescheduled. Use re-send instead.');
        }
    }

    /**
     * Same-day upgrade: pull every still-unclaimed, not-yet-expedited
     * delivery of the order forward. A `pending` delivery (still generating)
     * is included — the dispatcher reads the live row, so the new time is
     * honoured once generation finishes.
     */
    public function expediteOrder(int $orderId, Carbon $at): int
    {
        return EmailReadingDelivery::query()
            ->where('shopify_order_id', $orderId)
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_PENDING,
                EmailReadingDelivery::STATUS_GENERATED,
                EmailReadingDelivery::STATUS_COMPLIANCE_HOLD,
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
     * the admin opted out (e.g. fulfilled in Shopify manually).
     *
     * @throws RuntimeException when the delivery cannot be closed from its current state
     */
    public function markSentManually(EmailReadingDelivery $delivery, bool $fulfil = true): void
    {
        $this->assertNotSending($delivery);

        $updated = EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_PENDING,
                EmailReadingDelivery::STATUS_GENERATED,
                EmailReadingDelivery::STATUS_FAILED,
                EmailReadingDelivery::STATUS_COMPLIANCE_HOLD,
            ])
            ->update([
                'status' => EmailReadingDelivery::STATUS_SENT,
                'sent_at' => Carbon::now(),
                'sent_manually' => true,
                'error_message' => null,
                'claim_token' => null,
                'claimed_at' => null,
            ]);

        $delivery->refresh();

        if ($updated !== 1) {
            throw new RuntimeException('Only pending, generated, failed or compliance-hold readings can be marked as sent manually. Reactivate a cancelled reading first.');
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
    public function retryFulfillment(EmailReadingDelivery $delivery): void
    {
        $delivery->refresh();

        if (! $delivery->needsFulfillment()) {
            throw new RuntimeException('Only a sent, unfulfilled reading can have its fulfilment retried.');
        }

        $this->dispatchFulfillment($delivery->id);
    }

    /**
     * Whether a delivery may be hard-deleted (never while it is being sent).
     */
    public function canDelete(EmailReadingDelivery $delivery): bool
    {
        $delivery->refresh();

        return $delivery->status !== EmailReadingDelivery::STATUS_SENDING;
    }

    /**
     * Record that the mail left the building, under the owning claim. Called
     * immediately after Mail::send so a retry never sends a second copy.
     */
    public function recordMailSent(int $deliveryId, string $claimToken): bool
    {
        return EmailReadingDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', EmailReadingDelivery::STATUS_SENDING)
            ->where('claim_token', $claimToken)
            ->update(['sent_at' => Carbon::now()]) === 1;
    }

    /**
     * Finalize a send (`sending -> sent`) under the owning claim and queue
     * the Shopify fulfilment.
     */
    public function markSent(int $deliveryId, string $claimToken): bool
    {
        $updated = EmailReadingDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', EmailReadingDelivery::STATUS_SENDING)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => EmailReadingDelivery::STATUS_SENT,
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
     * claim is no longer ours (released/finalized elsewhere) — callers must
     * then not alert.
     */
    public function markSendFailed(int $deliveryId, string $claimToken, string $message): bool
    {
        return EmailReadingDelivery::query()
            ->whereKey($deliveryId)
            ->where('status', EmailReadingDelivery::STATUS_SENDING)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => EmailReadingDelivery::STATUS_FAILED,
                'error_message' => $message,
                'claim_token' => null,
                'claimed_at' => null,
            ]) === 1;
    }

    /**
     * Recover deliveries stuck in `sending` (worker died, job lost).
     *
     * - mail already recorded as sent -> finalize as `sent` (+ fulfilment)
     * - first stall                   -> release back to `generated`, claim
     *                                    cleared, so the next dispatch claims
     *                                    it under a fresh token and any
     *                                    straggling old job no-ops
     * - stalled again (max releases)  -> mark `failed` and notify an admin
     *
     * @return array{finalized:int,released:int,failed:int}
     */
    public function recoverStuck(): array
    {
        $cutoff = Carbon::now()->subMinutes(max(1, (int) config('email_reading.dispatch.stuck_after_minutes', 15)));
        $maxReleases = max(0, (int) config('email_reading.dispatch.max_releases', 1));

        $stuck = EmailReadingDelivery::query()
            ->where('status', EmailReadingDelivery::STATUS_SENDING)
            ->where('claimed_at', '<', $cutoff)
            ->get(['id', 'sent_at', 'release_count']);

        $result = ['finalized' => 0, 'released' => 0, 'failed' => 0];

        foreach ($stuck as $row) {
            $base = EmailReadingDelivery::query()
                ->whereKey($row->id)
                ->where('status', EmailReadingDelivery::STATUS_SENDING)
                ->where('claimed_at', '<', $cutoff);

            if ($row->sent_at !== null) {
                $updated = (clone $base)->whereNotNull('sent_at')->update([
                    'status' => EmailReadingDelivery::STATUS_SENT,
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
                    'status' => EmailReadingDelivery::STATUS_FAILED,
                    'error_message' => $message,
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);

                if ($updated === 1) {
                    $result['failed']++;
                    NotifyReadingFailureJob::dispatch((int) $row->id, $message)
                        ->onConnection(config('email_reading.queue.connection'))
                        ->onQueue(config('email_reading.queue.mail'));
                }

                continue;
            }

            $updated = (clone $base)->whereNull('sent_at')->update([
                'status' => EmailReadingDelivery::STATUS_GENERATED,
                'claim_token' => null,
                'claimed_at' => null,
                'release_count' => (int) $row->release_count + 1,
            ]);

            if ($updated === 1) {
                $result['released']++;
                Log::channel('shopify_webhooks')->warning('Stuck reading delivery released for re-dispatch', [
                    'delivery_id' => $row->id,
                ]);
            }
        }

        return $result;
    }

    /**
     * Count of due deliveries the dispatcher has not claimed for a while.
     * Feeds the health check: a non-zero value means the scheduler is not
     * running.
     */
    public function overdueCount(int $graceMinutes = 10): int
    {
        return EmailReadingDelivery::query()
            ->where('status', EmailReadingDelivery::STATUS_GENERATED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', Carbon::now()->subMinutes($graceMinutes))
            ->count();
    }

    private function dispatchSend(int $deliveryId, string $claimToken, bool $rethrow = false): bool
    {
        try {
            SendEmailReadingJob::dispatch($deliveryId, $claimToken)
                ->onConnection(config('email_reading.queue.connection'))
                ->onQueue(config('email_reading.queue.mail'));

            return true;
        } catch (Throwable $e) {
            // The claim stays; recoverStuck() releases it if no job ever runs.
            Log::channel('shopify_webhooks')->error('Failed to dispatch reading send job', [
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
        if (! (bool) config('email_reading.fulfillment.enabled', true)) {
            return;
        }

        MarkReadingOrderFulfilledJob::dispatch($deliveryId)
            ->onConnection(config('email_reading.queue.connection'))
            ->onQueue(config('email_reading.queue.mail'));
    }
}
