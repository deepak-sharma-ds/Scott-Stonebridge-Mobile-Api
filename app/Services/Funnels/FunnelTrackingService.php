<?php

namespace App\Services\Funnels;

use App\Models\Funnels\Funnel;
use App\Models\Funnels\FunnelOrder;
use App\Models\Funnels\FunnelStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records storefront events and attributed orders into the daily funnel counters.
 */
class FunnelTrackingService
{
    public const EVENTS = [
        'impression' => 'impressions',
        'accept'     => 'accepts',
        'reject'     => 'rejects',
        'checkout'   => 'checkout_initiates',
    ];

    /**
     * @param  array  $events  [{funnel_id, type}]
     * @return int number of events counted
     */
    public function recordEvents(array $events): int
    {
        $counts = [];
        foreach ($events as $event) {
            $column = self::EVENTS[$event['type'] ?? ''] ?? null;
            $funnelId = (int) ($event['funnel_id'] ?? 0);
            if ($column && $funnelId) {
                $counts[$funnelId][$column] = ($counts[$funnelId][$column] ?? 0) + 1;
            }
        }

        $known = Funnel::withTrashed()->whereIn('id', array_keys($counts))->pluck('id')->all();
        $total = 0;
        foreach ($counts as $funnelId => $columns) {
            if (! in_array($funnelId, $known, true)) {
                continue;
            }
            foreach ($columns as $column => $n) {
                $this->increment($funnelId, now()->toDateString(), $column, $n);
                $total += $n;
            }
        }

        return $total;
    }

    /**
     * Attribute an order (orders/create webhook) to the funnels whose items it contains.
     * Lines are attributed by the hidden _zb_funnel line property the widget adds.
     * Idempotent: a retried webhook doesn't count twice.
     */
    public function recordOrder(array $order): int
    {
        $byFunnel = [];

        foreach ($order['line_items'] ?? [] as $line) {
            $props = collect($line['properties'] ?? [])->mapWithKeys(fn ($p) => [$p['name'] ?? '' => $p['value'] ?? null]);
            $funnelId = (int) ($props['_zb_funnel'] ?? 0);
            if (! $funnelId) {
                continue;
            }

            $gross = (float) ($line['price'] ?? 0) * (int) ($line['quantity'] ?? 0);
            $discount = collect($line['discount_allocations'] ?? [])->sum(fn ($d) => (float) ($d['amount'] ?? 0));

            $byFunnel[$funnelId]['revenue'] = ($byFunnel[$funnelId]['revenue'] ?? 0) + $gross - $discount;
            $byFunnel[$funnelId]['discount'] = ($byFunnel[$funnelId]['discount'] ?? 0) + $discount;
            $byFunnel[$funnelId]['placement'] ??= $props['_zb_placement'] ?? null;
        }

        if (! $byFunnel || empty($order['id'])) {
            return 0;
        }

        $orderedAt = isset($order['created_at']) ? Carbon::parse($order['created_at']) : now();
        $known = Funnel::withTrashed()->whereIn('id', array_keys($byFunnel))->pluck('id')->all();
        $recorded = 0;

        foreach ($byFunnel as $funnelId => $totals) {
            if (! in_array($funnelId, $known, true)) {
                continue;
            }

            DB::transaction(function () use ($funnelId, $totals, $order, $orderedAt, &$recorded) {
                $row = FunnelOrder::firstOrCreate(
                    ['funnel_id' => $funnelId, 'shopify_order_id' => (string) $order['id']],
                    [
                        'order_name'         => $order['name'] ?? null,
                        'currency'           => $order['currency'] ?? null,
                        'order_total'        => (float) ($order['total_price'] ?? 0),
                        'attributed_revenue' => round($totals['revenue'], 2),
                        'discount_amount'    => round($totals['discount'], 2),
                        'placement'          => $totals['placement'],
                        'ordered_at'         => $orderedAt,
                    ]
                );

                if ($row->wasRecentlyCreated) {
                    $date = $orderedAt->toDateString();
                    $this->increment($funnelId, $date, 'conversions', 1);
                    FunnelStat::where(['funnel_id' => $funnelId, 'date' => $date])->increment('revenue', round($totals['revenue'], 2));
                    $recorded++;
                }
            });
        }

        return $recorded;
    }

    protected function increment(int $funnelId, string $date, string $column, int $by): void
    {
        $stat = FunnelStat::firstOrCreate(['funnel_id' => $funnelId, 'date' => $date]);
        $stat->increment($column, $by);
    }
}
