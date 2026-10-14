<?php

namespace App\Services\EmailReading;

use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Models\EmailReadingDelivery;
use App\Support\SafeHtml;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-facing operations over email reading deliveries.
 *
 * Keeps EmailReadingController thin (mirrors PackageService). Reuses the live
 * job pipeline (ProcessEmailReadingOrderJob / SendEmailReadingJob) so admin
 * actions behave identically to the webhook flow.
 */
class EmailReadingAdminService
{
    public function __construct(
        private readonly EmailReadingDeliveryLifecycle $lifecycle
    ) {}

    /**
     * Paginated, filtered delivery list for the admin index.
     *
     * @param  array<string,mixed>  $filters
     */
    public function paginateDeliveries(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return EmailReadingDelivery::with('product')
            ->status($filters['status'] ?? null)
            ->search($filters['search'] ?? null)
            ->engagement($filters['engagement'] ?? null)
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Persist edited data fields on a delivery. State is never changed here —
     * Cancel / Reactivate / Send now / Mark sent are explicit actions — and a
     * `sending` delivery is frozen. A changed schedule goes through the
     * lifecycle so it is validated against the live status.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException when the delivery is being sent or cannot be rescheduled
     */
    public function updateDelivery(EmailReadingDelivery $delivery, array $data): EmailReadingDelivery
    {
        $this->lifecycle->assertNotSending($delivery);

        $fields = [
            'customer_email' => $data['customer_email'] ?? $delivery->customer_email,
            'customer_name' => $data['customer_name'] ?? null,
        ];

        if (array_key_exists('ai_response', $data)) {
            $clean = SafeHtml::clean($data['ai_response']);
            $fields['ai_response'] = SafeHtml::isBlank($clean) ? null : $clean;
        }

        $saved = EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', '!=', EmailReadingDelivery::STATUS_SENDING)
            ->update($fields);

        if ($saved !== 1) {
            throw new RuntimeException('This reading is being sent right now. Try again in a minute.');
        }

        $delivery->refresh();

        $scheduledAt = $this->parseDate($data['scheduled_at'] ?? null);
        $expeditedAt = $this->parseDate($data['expedited_at'] ?? null);

        if (! $this->sameInstant($scheduledAt, $delivery->scheduled_at)
            || ! $this->sameInstant($expeditedAt, $delivery->expedited_at)) {
            $this->lifecycle->reschedule($delivery, $scheduledAt, $expeditedAt);
        }

        return $delivery;
    }

    /**
     * Send (or resend) a reading now, bypassing the schedule. Generates the
     * response synchronously first when none exists.
     *
     * @throws RuntimeException when the delivery cannot be sent from its current state
     */
    public function sendNow(EmailReadingDelivery $delivery, bool $resend = false): void
    {
        $this->lifecycle->sendNow($delivery, $resend);
    }

    /**
     * Cancel a not-yet-sent reading so it is never sent.
     *
     * @throws RuntimeException when the delivery is being sent right now
     */
    public function cancel(EmailReadingDelivery $delivery): bool
    {
        return $this->lifecycle->cancel($delivery);
    }

    /**
     * Bring a cancelled/failed reading back into the pipeline.
     *
     * @return string|null the new status, or null when it could not be reactivated
     *
     * @throws RuntimeException when the delivery is being sent right now
     */
    public function reactivate(EmailReadingDelivery $delivery): ?string
    {
        return $this->lifecycle->reactivate($delivery);
    }

    /**
     * Approve a reading held by the Compliance Gate and re-queue generation.
     *
     * @return bool false when the reading is not on compliance hold
     *
     * @throws RuntimeException when the delivery is being sent right now
     */
    public function approveComplianceHold(EmailReadingDelivery $delivery): bool
    {
        return $this->lifecycle->approveComplianceHold($delivery);
    }

    /**
     * Close a reading Scott already emailed by hand (no email is sent).
     *
     * @throws RuntimeException when the delivery cannot be closed from its current state
     */
    public function markSentManually(EmailReadingDelivery $delivery, bool $fulfil = true): void
    {
        $this->lifecycle->markSentManually($delivery, $fulfil);
    }

    /**
     * Re-queue a failed Shopify fulfilment.
     *
     * @throws RuntimeException when there is nothing to fulfil
     */
    public function retryFulfillment(EmailReadingDelivery $delivery): void
    {
        $this->lifecycle->retryFulfillment($delivery);
    }

    /**
     * Hard-delete a delivery; refused while it is being sent.
     */
    public function delete(EmailReadingDelivery $delivery): bool
    {
        if (! $this->lifecycle->canDelete($delivery)) {
            return false;
        }

        $delivery->delete();

        return true;
    }

    /**
     * Manually create a delivery (no Shopify order) and kick off generation.
     *
     * @param  array<string,mixed>  $data  Validated: email_reading_product_id,
     *                                     customer_email, customer_name?, answers[], scheduled_at?
     */
    public function createManual(array $data): EmailReadingDelivery
    {
        $delivery = EmailReadingDelivery::create([
            'shopify_order_id' => null,
            'shopify_line_item_id' => null,
            'email_reading_product_id' => $data['email_reading_product_id'],
            'customer_email' => $data['customer_email'],
            'customer_name' => $data['customer_name'] ?? null,
            'questions' => $this->buildQuestions($data['answers'] ?? []),
            'status' => EmailReadingDelivery::STATUS_PENDING,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);

        ProcessEmailReadingOrderJob::dispatch($delivery->id)
            ->onConnection(config('email_reading.queue.connection'))
            ->onQueue(config('email_reading.queue.process'));

        return $delivery;
    }

    /**
     * Stream the filtered delivery list as CSV.
     *
     * @param  array<string,mixed>  $filters
     */
    public function exportCsv(array $filters = []): StreamedResponse
    {
        $filename = 'email-readings-'.Carbon::now()->format('Ymd_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'ID', 'Order ID', 'Product', 'Customer Name', 'Customer Email',
            'Status', 'Model', 'Prompt Tokens', 'Completion Tokens',
            'Scheduled At', 'Sent At', 'Created At',
        ];

        $callback = function () use ($filters, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            EmailReadingDelivery::with('product')
                ->status($filters['status'] ?? null)
                ->search($filters['search'] ?? null)
                ->engagement($filters['engagement'] ?? null)
                ->when(! empty($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
                ->when(! empty($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
                ->latest()
                ->chunk(200, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->id,
                            $row->shopify_order_id,
                            $row->product?->name,
                            $row->customer_name,
                            $row->customer_email,
                            $row->status,
                            $row->model_used,
                            $row->prompt_tokens,
                            $row->completion_tokens,
                            $row->scheduled_at?->toDateTimeString(),
                            $row->sent_at?->toDateTimeString(),
                            $row->created_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value) : null;
    }

    private function sameInstant(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        // The admin form carries minute precision (datetime-local), the stored
        // value may carry seconds: compare by minute so an untouched field
        // never counts as a reschedule.
        return $a->format('Y-m-d H:i') === $b->format('Y-m-d H:i');
    }

    /**
     * Build the questions snapshot from admin-supplied answers keyed by schema
     * key. Mirrors ShopifyReadingWebhookController::mapProperties so the prompt
     * template's `{{ $key ?? $qN }}` fallbacks resolve identically.
     *
     * @param  array<string,mixed>  $answers  key => value
     * @return array<string,mixed>
     */
    private function buildQuestions(array $answers): array
    {
        $out = ['_raw' => []];
        $idx = 1;

        foreach ($answers as $key => $value) {
            $value = (string) $value;
            $normalized = EmailReadingDelivery::normalizeKey((string) $key);

            if ($normalized !== '') {
                $out[$normalized] = $value;
            }
            $out['q'.$idx] = $value;
            $out['_raw'][(string) $key] = $value;
            $idx++;
        }

        return $out;
    }
}
