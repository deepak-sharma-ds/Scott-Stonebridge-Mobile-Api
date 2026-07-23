<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Jobs\Campaign\SendCampaignEmailJob;
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
    private const CANCELLABLE_STATUSES = [
        CampaignDelivery::STATUS_PENDING,
        CampaignDelivery::STATUS_GENERATED,
        CampaignDelivery::STATUS_FAILED,
    ];

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
     * Persist edited fields on a delivery, including a manual re-pair of the
     * Campaign Product for recovering an Attribution Failure (see ADR 0005).
     *
     * @param  array<string,mixed>  $data
     */
    public function updateDelivery(CampaignDelivery $delivery, array $data): CampaignDelivery
    {
        $delivery->fill([
            'customer_email' => $data['customer_email'] ?? $delivery->customer_email,
            'customer_name' => $data['customer_name'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'expedited_at' => $data['expedited_at'] ?? null,
        ]);

        if (array_key_exists('campaign_product_id', $data)) {
            $delivery->campaign_product_id = $data['campaign_product_id'] ?: null;
        }

        if (! empty($data['status'])) {
            $delivery->status = $data['status'];
        }

        $delivery->save();

        return $delivery;
    }

    /**
     * Send (or resend) a delivery now, bypassing SendCampaignEmailJob's
     * pending-only guard. Requires a resolved Campaign Product with an
     * existing Campaign Response — there is no live-generation fallback, so
     * an unresolved pairing must be fixed via re-pair (updateDelivery) first.
     *
     * @throws RuntimeException when no response is resolvable yet
     */
    public function sendNow(CampaignDelivery $delivery): void
    {
        $delivery->loadMissing('campaignProduct.response');

        if (! $delivery->campaignProduct?->response) {
            throw new RuntimeException('No campaign response resolved for this delivery yet. Re-pair it to a Campaign Product with a generated response first.');
        }

        if ($delivery->status !== CampaignDelivery::STATUS_PENDING) {
            $delivery->forceFill(['status' => CampaignDelivery::STATUS_PENDING])->save();
        }

        SendCampaignEmailJob::dispatch($delivery->id)
            ->onConnection(config('campaign_email.queue.connection'))
            ->onQueue(config('campaign_email.queue.mail'));
    }

    /**
     * Mark a delivery cancelled so its queued send job no-ops. Includes
     * `failed` (unlike the auto-cancel webhook) so an admin can write off an
     * unrecoverable Attribution Failure instead of leaving it stuck.
     */
    public function cancel(CampaignDelivery $delivery): void
    {
        if (in_array($delivery->status, self::CANCELLABLE_STATUSES, true)) {
            $delivery->forceFill(['status' => CampaignDelivery::STATUS_CANCELLED])->save();
        }
    }

    /**
     * Hard-delete a delivery, refusing to erase the audit trail of a real
     * send. Returns false (no-op) when the delivery has already been sent.
     */
    public function delete(CampaignDelivery $delivery): bool
    {
        if ($delivery->status === CampaignDelivery::STATUS_SENT) {
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
