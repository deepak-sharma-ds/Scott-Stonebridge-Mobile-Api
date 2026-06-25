<?php

namespace App\Services\EmailReading;

use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Jobs\EmailReading\SendEmailReadingJob;
use App\Models\EmailReadingDelivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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
        private readonly EmailReadingGenerationService $generation
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
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Persist edited fields on a delivery.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateDelivery(EmailReadingDelivery $delivery, array $data): EmailReadingDelivery
    {
        $delivery->fill([
            'customer_email' => $data['customer_email'] ?? $delivery->customer_email,
            'customer_name' => $data['customer_name'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'expedited_at' => $data['expedited_at'] ?? null,
        ]);

        if (array_key_exists('ai_response', $data)) {
            $delivery->ai_response = $data['ai_response'];
        }

        if (! empty($data['status'])) {
            $delivery->status = $data['status'];
        }

        $delivery->save();

        return $delivery;
    }

    /**
     * Send (or resend) a reading now, bypassing the scheduled delay.
     *
     * Ensures the AI response exists (generates if missing), forces the row to
     * `generated` so SendEmailReadingJob's status guard lets it through, then
     * dispatches the send with no delay.
     */
    public function sendNow(EmailReadingDelivery $delivery, bool $resend = false): void
    {
        if (empty($delivery->ai_response)) {
            // generate() persists ai_response + flips status to generated.
            $this->generation->generate($delivery);
            $delivery->refresh();
        }

        // Re-arm the status guard for an already-sent (resend) or failed row.
        if ($delivery->status !== EmailReadingDelivery::STATUS_GENERATED) {
            $delivery->forceFill(['status' => EmailReadingDelivery::STATUS_GENERATED])->save();
        }

        SendEmailReadingJob::dispatch($delivery->id)
            ->onConnection(config('email_reading.queue.connection'))
            ->onQueue(config('email_reading.queue.mail'));
    }

    /**
     * Mark a reading cancelled/held so its scheduled send job no-ops.
     */
    public function cancel(EmailReadingDelivery $delivery): void
    {
        $cancellable = [
            EmailReadingDelivery::STATUS_PENDING,
            EmailReadingDelivery::STATUS_GENERATED,
            EmailReadingDelivery::STATUS_FAILED,
        ];

        if (in_array($delivery->status, $cancellable, true)) {
            $delivery->forceFill(['status' => EmailReadingDelivery::STATUS_CANCELLED])->save();
        }
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
