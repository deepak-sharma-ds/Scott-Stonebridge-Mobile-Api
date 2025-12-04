<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\Shopify\ShopifyAnalyticsService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    // protected $analytics;

    // public function __construct(AnalyticsService $analytics)
    // {
    //     // $this->middleware('auth:admin'); // ensure admin guard
    //     $this->analytics = $analytics;
    // }

    // public function dashboard(Request $request)
    // {
    //     $range = $request->get('range', '30d');
    //     $kpis = $this->analytics->getKpis($range);
    //     return response()->json(['data' => $kpis]);
    // }

    // public function topProducts(Request $request)
    // {
    //     $limit = (int) $request->get('limit', 10);
    //     $data = $this->analytics->getTopProducts($limit);
    //     return response()->json(['data' => $data]);
    // }

    // public function activityTrends(Request $request)
    // {
    //     $range = $request->get('range', '30d');
    //     $limit = (int)$request->get('limit', 10);
    //     $data = $this->analytics->getUserActivityTrends($range, $limit);
    //     return response()->json(['data' => $data]);
    // }

    protected ShopifyAnalyticsService $analytics;

    public function __construct(ShopifyAnalyticsService $analytics)
    {
        $this->analytics = $analytics;
    }

    public function dashboard(Request $request)
    {
        $from = $request->get('from');
        $to = $request->get('to');

        return response()->json([
            'data' => $this->analytics->getDashboardKPIs($from, $to, 30)
        ]);
    }

    public function salesTimeseries()
    {
        return response()->json([
            'data' => $this->analytics->getSalesTimeseries(30)
        ]);
    }

    public function topProducts()
    {
        return response()->json([
            'data' => $this->analytics->getTopProducts(10)
        ]);
    }

    public function activityTrends(Request $request)
    {
        $days = request('days', 30);

        return response()->json([
            'data'   => $this->analytics->getActivityTrends($days)
        ]);
    }
}
