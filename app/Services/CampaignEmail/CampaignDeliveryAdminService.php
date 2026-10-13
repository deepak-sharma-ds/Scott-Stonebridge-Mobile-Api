<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Models\CampaignDelivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-facing operations over campaign deliveries.
 *
 * Keeps CampaignDeliveryController thin (mirrors EmailReadingAdminService).
 * Unlike Email Reading Automation, a Campaign Delivery has no per-delivery
 * response to generate on demand — the Campaign Response is pre-generated
 * once per Campaign Product (see ADR 0003), so Send/Resend here only ever
 * dispatches against an already-resolved pairing.
 */
class CampaignDeliveryAdminService
{
    public function __construct(
        private readonly CampaignDeliveryLifecycle $lifecycle
    ) {}

    /**
     * Paginated, filtered delivery list for the admin index.
     *
     * @param  array<string,mixed>  $filters
     */
    public function paginateDeliveries(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Persist edited data fields on a delivery, including a manual re-pair of
     * the Campaign Product for recovering an Attribution Failure (ADR 0005).
     *
     * State is never changed here: after re-pairing, the admin uses the
     * explicit Reactivate / Send now actions. A `sending` delivery is frozen,
     * and a changed schedule goes through the lifecycle so it is validated
     * against the live status.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException when the delivery is being sent or cannot be rescheduled
     */
    public function updateDelivery(CampaignDelivery $delivery, array $data): CampaignDelivery
    {
        $this->lifecycle->assertNotSending($delivery);

        $fields = [
            'customer_email' => $data['customer_email'] ?? $delivery->customer_email,
            'customer_name' => $data['customer_name'] ?? null,
        ];

        if (array_key_exists('campaign_product_id', $data)) {
            $fields['campaign_product_id'] = $data['campaign_product_id'] ?: null;
        }

        $saved = CampaignDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', '!=', CampaignDelivery::STATUS_SENDING)
            ->update($fields);

        if ($saved !== 1) {
            throw new RuntimeException('This delivery is being sent right now. Try again in a minute.');
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
     * Send (or resend) a delivery now. Requires a resolved Campaign Product
     * with an existing Campaign Response.
     *
     * @throws RuntimeException when the delivery cannot be sent from its current state
     */
    public function sendNow(CampaignDelivery $delivery, bool $resend = false): void
    {
        $this->lifecycle->sendNow($delivery, $resend);
    }

    /**
     * Cancel a not-yet-sent delivery. Includes `failed` so an admin can write
     * off an unrecoverable Attribution Failure.
     *
     * @throws RuntimeException when the delivery is being sent right now
     */
    public function cancel(CampaignDelivery $delivery): bool
    {
        return $this->lifecycle->cancel($delivery);
    }

    /**
     * Bring a cancelled/failed delivery back to `pending`.
     *
     * @return string|null the new status, or null when it could not be reactivated
     *
     * @throws RuntimeException when no response is resolved or the delivery is sending
     */
    public function reactivate(CampaignDelivery $delivery): ?string
    {
        return $this->lifecycle->reactivate($delivery);
    }

    /**
     * Close a delivery already emailed by hand (no email is sent).
     *
     * @throws RuntimeException when the delivery cannot be closed from its current state
     */
    public function markSentManually(CampaignDelivery $delivery, bool $fulfil = true): void
    {
        $this->lifecycle->markSentManually($delivery, $fulfil);
    }

    /**
     * Re-queue a failed Shopify fulfilment.
     *
     * @throws RuntimeException when there is nothing to fulfil
     */
    public function retryFulfillment(CampaignDelivery $delivery): void
    {
        $this->lifecycle->retryFulfillment($delivery);
    }

    /**
     * Hard-delete a delivery, refusing to erase the audit trail of a real
     * send and never while it is being sent. Returns false (no-op) then.
     */
    public function delete(CampaignDelivery $delivery): bool
    {
        if (! $this->lifecycle->canDelete($delivery)) {
            return false;
        }

        $delivery->delete();

        return true;
    }

    /**
     * Stream the filtered delivery list as CSV.
     *
     * @param  array<string,mixed>  $filters
     */
    public function exportCsv(array $filters = []): StreamedResponse
    {
        $filename = 'campaign-deliveries-'.Carbon::now()->format('Ymd_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'ID', 'Order ID', 'Line Item ID', 'Campaign Name', 'Campaign Key', 'Product',
            'Customer Name', 'Customer Email', 'Status', 'Error Message',
            'Scheduled At', 'Sent At', 'Fulfilled At', 'Created At',
        ];

        $callback = function () use ($filters, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            $this->filteredQuery($filters)
                ->latest()
                ->chunk(200, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        $campaign = $row->campaignProduct?->marketingCampaign;

                        fputcsv($out, [
                            $row->id,
                            $row->shopify_order_id,
                            $row->shopify_line_item_id,
                            $campaign?->name,
                            $campaign?->campaign_key,
                            $row->campaignProduct?->product_title,
                            $row->customer_name,
                            $row->customer_email,
                            $row->status,
                            $row->error_message,
                            $row->scheduled_at?->toDateTimeString(),
                            $row->sent_at?->toDateTimeString(),
                            $row->fulfilled_at?->toDateTimeString(),
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
     * Shared filter chain for both the paginated index and the CSV export so
     * "export what you're looking at" always holds.
     *
     * @param  array<string,mixed>  $filters
     */
    private function filteredQuery(array $filters)
    {
        return CampaignDelivery::with(['campaignProduct.marketingCampaign', 'campaignProduct.response'])
            ->status($filters['status'] ?? null)
            ->search($filters['search'] ?? null)
            ->campaign(! empty($filters['campaign']) ? (int) $filters['campaign'] : null)
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']));
    }
}
