<?php

namespace App\Services;

use App\Repositories\Analytics\LocalAnalyticsRepository;
use App\Models\AnalyticsSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class AnalyticsService
{
    protected $localRepo;
    protected $shopify;

    public function __construct(LocalAnalyticsRepository $localRepo, APIShopifyService $shopify)
    {
        $this->localRepo = $localRepo;
        $this->shopify = $shopify;
    }

    /**
     * Dashboard KPIs - merges Shopify and local.
     *
     * $range: ['start' => Carbon, 'end' => Carbon] or '30d'
     */
    public function getKpis($period = '30d')
    {
        $cacheKey = "analytics:kpis:{$period}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($period) {
            $to = Carbon::now();
            $from = $this->parsePeriod($period);

            // Local metrics
            $downloadsLocal = $this->localRepo->countDownloads($from);
            $activeUsersLocal = $this->localRepo->activeUsersCount($from);
            $bookings = $this->localRepo->bookingsCount($from);
            $audioPurchases = $this->localRepo->audioSubscriptionPurchasesCount($from);

            // Shopify metrics
            // ordersCount and salesTotal are pulled from Shopify; implement efficient paginated calls in APIShopifyService
            $ordersCountShopify = $this->shopify->getOrdersCountBetween($from, $to); // implement method
            $salesShopify = $this->shopify->getSalesTotalBetween($from, $to); // implement method

            // Combine
            return [
                'downloads' => ['local' => $downloadsLocal],
                'active_users' => ['local' => $activeUsersLocal],
                'orders' => ['shopify' => $ordersCountShopify],
                'sales' => ['shopify' => $salesShopify],
                'bookings' => ['local' => $bookings],
                'audio_subscription_purchases' => ['local' => $audioPurchases],
            ];
        });
    }

    public function getTopProducts($limit = 10)
    {
        // Prefer Shopify best-selling; then supplement with local logs (views/wishlist)
        $cacheKey = "analytics:top_products:{$limit}";
        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($limit) {
            $shopifyTop = $this->shopify->getTopProducts($limit); // assume returns array with id/title/sales
            $localWishlist = $this->localRepo->wishlistTrends(null, $limit);

            return [
                'shopify_best_selling' => $shopifyTop,
                'local_wishlist_trends' => $localWishlist,
            ];
        });
    }

    public function getUserActivityTrends($period = '30d', $limit = 10)
    {
        $from = $this->parsePeriod($period);
        $localSearches = $this->localRepo->topSearches($from, $limit);
        $wishlist = $this->localRepo->wishlistTrends($from, $limit);
        return compact('localSearches', 'wishlist');
    }

    protected function parsePeriod($period)
    {
        if ($period instanceof \DateTimeInterface) return $period;
        if (preg_match('/^(\d+)d$/', $period, $m)) {
            return Carbon::now()->subDays((int)$m[1]);
        }
        return Carbon::now()->subDays(30);
    }
}
