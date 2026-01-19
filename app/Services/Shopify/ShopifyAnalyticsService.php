<?php

namespace App\Services\Shopify;

use App\Repositories\Analytics\LocalAnalyticsRepository;
use Carbon\Carbon;

class ShopifyAnalyticsService
{
    protected ShopifyManager $manager;
    protected LocalAnalyticsRepository $localRepo;

    public function __construct(ShopifyManager $manager, LocalAnalyticsRepository $localRepo)
    {
        $this->manager = $manager;
        $this->localRepo = $localRepo;
    }

    /**
     * ------------------------------------------------------------------
     * 1. GET TOTAL ORDERS BETWEEN DATES (GraphQL)
     * ------------------------------------------------------------------
     */
    public function getOrdersCount($from, $to)
    {
        $after = null;
        $count = 0;

        $filter = sprintf(
            'created_at:>=%s created_at:<=%s status:any',
            $from,
            $to
        );

        // Convert filter to GraphQL safe quoted string
        $filter = json_encode($filter);

        // $fromDate = substr($from, 0, 10); // YYYY-MM-DD
        // $toDate = substr($to, 0, 10);

        // $filter = "created_at:>=$fromDate created_at:<=$toDate";

        do {
            $afterPart = $after ? ', after: ' . json_encode($after) : '';

            $query =
                'query {' .
                ' orders(first: 250' . $afterPart . ', query: ' . $filter . ') {' .
                ' pageInfo { hasNextPage endCursor }' .
                ' edges { cursor }' .
                ' }' .
                '}';

            $res = $this->manager->admin()->request($query);

            if (!empty($res['errors'])) {
                dd($query, $res); // DEBUG
            }

            $edges = $res['data']['orders']['edges'] ?? [];
            $count += count($edges);
            // dd($res['data']['orders']['edges']);

            $pageInfo = $res['data']['orders']['pageInfo'] ?? [];
            $after = $pageInfo['hasNextPage'] ? $pageInfo['endCursor'] : null;
        } while ($after);

        return $count;
    }


    /**
     * ------------------------------------------------------------------
     * 2. GET TOTAL SALES BETWEEN DATES (GraphQL)
     * ------------------------------------------------------------------
     */
    public function getSalesTotal($from, $to)
    {
        $after = null;
        $total = 0;

        // Shopify only accepts YYYY-MM-DD for date searches
        $fromDate = substr($from, 0, 10);
        $toDate   = substr($to, 0, 10);

        // Build safe filter
        $filter = json_encode("created_at:>=$fromDate created_at:<=$toDate");

        do {
            // After must ONLY be included when value exists
            $afterPart = $after ? ', after: ' . json_encode($after) : '';

            $query = <<<GQL
        query {
            orders(
                first: 250
                $afterPart
                query: $filter
            ) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        totalPriceSet {
                            shopMoney {
                                amount
                            }
                        }
                    }
                }
            }
        }
        GQL;

            $res = $this->manager->admin()->request($query);
            // dd($res);
            // Debug if needed:
            if (!empty($res['errors'])) {
                dd("QUERY:", $query, "ERRORS:", $res);
            }

            foreach ($res['data']['orders']['edges'] as $edge) {
                $total += (float) $edge['node']['totalPriceSet']['shopMoney']['amount'];
            }

            $pageInfo = $res['data']['orders']['pageInfo'];
            $after = $pageInfo['hasNextPage'] ? $pageInfo['endCursor'] : null;
        } while ($after);

        return $total;
    }


    /**
     * ------------------------------------------------------------------
     * 3. GET DAILY SALES TIMESERIES (GraphQL grouped in PHP)
     * ------------------------------------------------------------------
     */
    public function getSalesTimeseries($days = 15)
    {
        $from = Carbon::now()->subDays($days);
        $to = Carbon::now();

        $orders = $this->getOrdersBetween($from, $to);
        $collection = collect($orders);

        // Auto grouping logic
        if ($days <= 16) {
            return $this->groupDaily($collection);
        } elseif ($days <= 61) {
            return $this->groupWeekly($collection);
        } else {
            return $this->groupMonthly($collection);
        }

        // Group by Day
        return collect($orders)
            ->groupBy(fn($o) => Carbon::parse($o['created_at'])->format('Y-m-d'))
            ->map(fn($rows, $day) => [
                'date' => Carbon::parse($day)->format('d M Y'),
                'sales' => $rows->sum('amount')
            ])
            ->values();
    }

    /* ---------------- DAILY ---------------- */
    private function groupDaily($collection)
    {
        return $collection->groupBy(
            fn($o) =>
            Carbon::parse($o['created_at'])->format('Y-m-d')
        )
            ->map(function ($rows, $day) {
                return [
                    'date' => Carbon::parse($day)->format('d M Y'),
                    'sales' => $rows->sum('amount')
                ];
            })
            ->sortBy('date')
            ->values();
    }

    /* ---------------- WEEKLY ---------------- */
    private function groupWeekly($collection)
    {
        return $collection->groupBy(
            fn($o) =>
            Carbon::parse($o['created_at'])->startOfWeek()->format('Y-m-d')
        )
            ->map(function ($rows, $weekStart) {
                $formatted = Carbon::parse($weekStart)->format('d M') . " - " .
                    Carbon::parse($weekStart)->endOfWeek()->format('d M');

                return [
                    'date' => "Week: " . $formatted,
                    'sales' => $rows->sum('amount')
                ];
            })
            ->sortBy('date')
            ->values();
    }

    /* ---------------- MONTHLY ---------------- */
    private function groupMonthly($collection)
    {
        return $collection->groupBy(
            fn($o) =>
            Carbon::parse($o['created_at'])->format('Y-m')
        )
            ->map(function ($rows, $ym) {
                return [
                    'date' => Carbon::parse($ym . "-01")->format('M Y'),
                    'sales' => $rows->sum('amount')
                ];
            })
            ->sortBy('date')
            ->values();
    }

    /**
     * Helper: Fetch all orders (date + total)
     */
    public function getOrdersBetween($from, $to)
    {
        $after = null;
        $rows = [];

        // Create safe filter string
        $filter = sprintf(
            'created_at:>=%s created_at:<=%s',
            $from,
            $to
        );

        $filter = json_encode($filter); // IMPORTANT

        do {
            $afterPart = $after ? ', after: ' . json_encode($after) : '';

            $query =
                'query {' .
                ' orders(first: 250' . $afterPart . ', query: ' . $filter . ') {' .
                '   pageInfo { hasNextPage endCursor }' .
                '   edges {' .
                '     node {' .
                '       createdAt' .
                '       totalPriceSet { shopMoney { amount } }' .
                '     }' .
                '   }' .
                ' }' .
                '}';

            $res = $this->manager->admin()->request($query);

            if (!empty($res['errors'])) {
                dd($query, $res);
            }

            foreach ($res['data']['orders']['edges'] as $edge) {
                $rows[] = [
                    'created_at' => $edge['node']['createdAt'],
                    'amount'     => (float) $edge['node']['totalPriceSet']['shopMoney']['amount'],
                ];
            }

            $pageInfo = $res['data']['orders']['pageInfo'];
            $after = $pageInfo['hasNextPage'] ? $pageInfo['endCursor'] : null;
        } while ($after);

        return $rows;
    }

    /**
     * ------------------------------------------------------------------
     * 4. TOP SELLING PRODUCTS
     * ------------------------------------------------------------------
     */
    public function getTopProducts($limit = 10, $days = 30)
    {
        $from = Carbon::now()->subDays($days)->toIso8601String();
        $to   = Carbon::now()->toIso8601String();

        // Use order.lines (GraphQL)
        // Best way without ShopifyQL
        $query = <<<GQL
        query {
          orders(first: $limit, query: "created_at:>={$from} created_at:<={$to} status:any") {
            edges {
              node {
                lineItems(first: 20) {
                  edges {
                    node {
                      title
                      quantity
                    }
                  }
                }
              }
            }
          }
        }
        GQL;

        $res = $this->manager->admin()->request($query);

        $items = [];

        foreach ($res['data']['orders']['edges'] as $order) {
            foreach ($order['node']['lineItems']['edges'] as $line) {
                $title = $line['node']['title'];
                $qty   = (int)$line['node']['quantity'];

                $items[$title] = ($items[$title] ?? 0) + $qty;
            }
        }

        arsort($items);

        return collect($items)
            ->take($limit)
            ->map(fn($qty, $title) => [
                'title' => $title,
                'sales' => $qty
            ])
            ->values();
    }

    /**
     * ------------------------------------------------------------------
     * 5. MERGED KPI DATA (Shopify + App)
     * ------------------------------------------------------------------
     */
    public function getDashboardKPIs($from, $to, $days = 30)
    {
        // $from = Carbon::now()->subDays($days)->toIso8601String();
        // $to   = Carbon::now()->toIso8601String();
        $from = $from ?: Carbon::now()->subDays($days)->toIso8601String();
        $to   = $to ?: Carbon::now()->toIso8601String();

        return [
            // 'shopify_orders'     => $this->getOrdersCount($from, $to),
            // 'shopify_sales'      => $this->getSalesTotal($from, $to),
            'downloads'          => $this->localRepo->countDownloads(Carbon::parse($from)),
            // 'active_users'       => $this->localRepo->activeUsersCount(Carbon::parse($from)),
            'bookings'           => $this->localRepo->bookingsCount(Carbon::parse($from)),
            'audio_purchases'    => $this->localRepo->audioSubscriptionPurchasesCount(Carbon::parse($from)),
        ];
    }

    /**
     * ------------------------------------------------------------------
     * 6. USER ACTIVITY TRENDS
     * ------------------------------------------------------------------
     */
    public function getActivityTrends($days = 30)
    {
        $from = Carbon::now()->subDays($days);

        return [
            'top_searches'    => $this->localRepo->topSearches($from),
            'wishlist_trends' => $this->localRepo->wishlistTrends($from),
        ];
    }
}
