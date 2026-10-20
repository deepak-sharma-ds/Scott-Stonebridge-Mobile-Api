<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Http\Controllers\Controller;
use App\Services\Funnels\FunnelAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsApiController extends Controller
{
    public function __construct(protected FunnelAnalyticsService $analytics)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($this->analytics->overview($from, $to, $request->integer('funnel') ?: null));
    }

    public function funnels(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $rows = collect($this->analytics->funnels($from, $to, $request->input('q'), (string) $request->input('sort', 'revenue'), (string) $request->input('direction', 'desc')));

        $perPage = 10;
        $page = max(1, $request->integer('page', 1));

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values()->all(),
            'meta' => ['page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $perPage)), 'total' => $rows->count()],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $page = $this->analytics->orderList($from, $to, $request->input('q'));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($o) => [
                'order_id'    => $o->shopify_order_id,
                'order_name'  => $o->order_name,
                'funnel'      => $o->funnel?->name,
                'funnel_id'   => $o->funnel_id,
                'offer_type'  => 'bundle',
                'placement'   => $o->placement,
                'order_total' => (float) $o->order_total,
                'revenue'     => (float) $o->attributed_revenue,
                'discount'    => (float) $o->discount_amount,
                'ordered_at'  => $o->ordered_at?->toIso8601String(),
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * ?from=YYYY-MM-DD&to=YYYY-MM-DD, default: last 30 days. Max 366 days.
     */
    protected function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return [$from, $to];
    }
}
