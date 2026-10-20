<?php

namespace App\Services\Funnels;

use App\Models\Funnels\Funnel;
use App\Models\Funnels\FunnelOrder;
use App\Models\Funnels\FunnelStat;
use Illuminate\Support\Carbon;

/**
 * Numbers for the Analytics page (same metrics as the reference app).
 */
class FunnelAnalyticsService
{
    public const METRICS = ['impressions', 'conversions', 'revenue', 'checkout_initiates', 'accepts', 'rejects'];

    /**
     * KPI cards, daily series, shopper behaviour and the last 7 days.
     */
    public function overview(Carbon $from, Carbon $to, ?int $funnelId = null): array
    {
        $stats = $this->stats($from, $to, $funnelId)->get();
        $orders = $this->orders($from, $to, $funnelId);

        $impressions = (int) $stats->sum('impressions');
        $conversions = (int) $stats->sum('conversions');
        $distinctOrders = (clone $orders)->distinct()->count('shopify_order_id');
        // AOV: average order total of distinct orders that contain funnel items
        $orderTotals = (clone $orders)->get(['shopify_order_id', 'order_total'])->unique('shopify_order_id');

        // Daily series, zero-filled
        $byDate = $stats->groupBy('date');
        $series = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $rows = $byDate->get($d->toDateString(), collect());
            $point = ['date' => $d->toDateString()];
            foreach (self::METRICS as $metric) {
                $point[$metric] = $metric === 'revenue' ? round((float) $rows->sum('revenue'), 2) : (int) $rows->sum($metric);
            }
            $series[] = $point;
        }

        // Weekly conversions: last 7 days up to $to
        $weekFrom = $to->copy()->subDays(6)->startOfDay();
        $week = $this->stats($weekFrom, $to, $funnelId)->get()->groupBy('date');
        $weekly = [];
        for ($d = $weekFrom->copy(); $d->lte($to); $d->addDay()) {
            $weekly[] = ['date' => $d->toDateString(), 'conversions' => (int) $week->get($d->toDateString(), collect())->sum('conversions')];
        }

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis'  => [
                'revenue'         => round((float) (clone $orders)->sum('attributed_revenue'), 2),
                'purchases'       => $distinctOrders,
                'aov'             => $orderTotals->count() ? round($orderTotals->avg('order_total'), 2) : 0,
                'conversion_rate' => $impressions ? round($conversions / $impressions * 100, 2) : 0,
            ],
            'behaviour' => [
                'views'     => $impressions,
                'additions' => (int) $stats->sum('accepts'),
                'checkouts' => (int) $stats->sum('checkout_initiates'),
                'declines'  => (int) $stats->sum('rejects'),
            ],
            'series' => $series,
            'weekly' => $weekly,
        ];
    }

    /**
     * Top performing funnels table.
     */
    public function funnels(Carbon $from, Carbon $to, ?string $search = null, string $sort = 'revenue', string $direction = 'desc'): array
    {
        $stats = $this->stats($from, $to)
            ->selectRaw('funnel_id, SUM(impressions) as impressions, SUM(conversions) as conversions, SUM(revenue) as revenue')
            ->groupBy('funnel_id')
            ->get()
            ->keyBy('funnel_id');

        $rows = Funnel::withTrashed()
            ->whereIn('id', $stats->keys())
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->get()
            ->map(function (Funnel $f) use ($stats) {
                $s = $stats[$f->id];
                $impressions = (int) $s->impressions;
                $conversions = (int) $s->conversions;

                return [
                    'id'              => $f->id,
                    'name'            => $f->name,
                    'deleted'         => $f->trashed(),
                    'pages'           => $f->pageLabels(),
                    'type'            => 'Bundle',
                    'conversions'     => $conversions,
                    'conversion_rate' => $impressions ? round($conversions / $impressions * 100, 1) : 0,
                    'revenue'         => round((float) $s->revenue, 2),
                    'page_views'      => $impressions,
                ];
            });

        $allowed = ['name', 'conversions', 'conversion_rate', 'revenue', 'page_views'];
        $sort = in_array($sort, $allowed, true) ? $sort : 'revenue';
        $rows = $direction === 'asc' ? $rows->sortBy($sort) : $rows->sortByDesc($sort);

        return $rows->values()->all();
    }

    /**
     * Orders generated with funnels.
     */
    public function orderList(Carbon $from, Carbon $to, ?string $search = null, int $perPage = 10)
    {
        return $this->orders($from, $to)
            ->with('funnel:id,name')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('shopify_order_id', 'like', "%{$search}%")
                ->orWhere('order_name', 'like', "%{$search}%")
                ->orWhereHas('funnel', fn ($f) => $f->where('name', 'like', "%{$search}%"))))
            ->orderByDesc('ordered_at')
            ->paginate($perPage);
    }

    /**
     * All-time conversions and revenue per funnel, for the Funnels list.
     *
     * @return array<int, array{conversions: int, revenue: float}>
     */
    public function totalsByFunnel(array $funnelIds): array
    {
        return FunnelStat::whereIn('funnel_id', $funnelIds)
            ->selectRaw('funnel_id, SUM(conversions) as conversions, SUM(revenue) as revenue')
            ->groupBy('funnel_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->funnel_id => ['conversions' => (int) $r->conversions, 'revenue' => round((float) $r->revenue, 2)]])
            ->all();
    }

    protected function stats(Carbon $from, Carbon $to, ?int $funnelId = null)
    {
        return FunnelStat::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($funnelId, fn ($q) => $q->where('funnel_id', $funnelId));
    }

    protected function orders(Carbon $from, Carbon $to, ?int $funnelId = null)
    {
        return FunnelOrder::query()
            ->whereBetween('ordered_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($funnelId, fn ($q) => $q->where('funnel_id', $funnelId));
    }
}
