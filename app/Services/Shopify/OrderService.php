<?php

namespace App\Services\Shopify;

use App\Contracts\Services\OrderServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Order\OrderDTO;
use App\Services\Base\BaseService;
use App\Exceptions\ShopifyNotFoundException;
use Illuminate\Support\Collection;

class OrderService extends BaseService implements OrderServiceInterface
{
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient
    ) {
        parent::__construct();
    }

    /**
     * Get customer orders
     *
     * @param string $accessToken Customer access token
     * @param int $limit Number of orders to fetch
     * @param string|null $cursor Pagination cursor
     * @param string|null $fulfillmentStatus Filter by fulfillment status
     * @return Collection Collection of OrderDTO instances
     */
    public function getOrders(string $accessToken, int $limit, ?string $cursor, ?string $fulfillmentStatus = null): Collection
    {
        try {
            $this->logPerformanceStart('getOrders');

            $variables = [
                'accessToken' => $accessToken,
                'limit' => $limit,
                'after' => $cursor,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/orders/get_customer_orders', $variables);

            if (empty($response['data']['customer'])) {
                throw new ShopifyNotFoundException('Customer not found or invalid access token');
            }

            $orders = collect($response['data']['customer']['orders']['edges'] ?? [])
                ->map(fn($edge) => OrderDTO::fromShopifyResponse($edge['node']));

            // Apply fulfillment status filter
            if ($fulfillmentStatus !== null) {
                $orders = $this->filterByFulfillmentStatus($orders, $fulfillmentStatus);
            }

            $this->logPerformanceEnd('getOrders', [
                'count' => $orders->count(),
                'fulfillment_status_filter' => $fulfillmentStatus,
                'has_next_page' => $response['data']['customer']['orders']['pageInfo']['hasNextPage'] ?? false,
            ]);

            return $orders;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch customer orders', $e, [
                'limit' => $limit,
                'cursor' => $cursor,
                'fulfillment_status' => $fulfillmentStatus,
            ]);
            throw $e;
        }
    }

    /**
     * Filter orders by fulfillment status
     * 
     * Rules:
     * - "FULFILLED" or "fulfilled" → Show only fulfilled orders
     * - "UNFULFILLED" or empty → Show all except fulfilled orders
     * - null → Show all orders (no filter)
     *
     * @param Collection $orders
     * @param string $status
     * @return Collection
     */
    protected function filterByFulfillmentStatus(Collection $orders, string $status): Collection
    {
        $status = strtoupper(trim($status));

        // If status is "FULFILLED", show only fulfilled orders
        if ($status === 'FULFILLED') {
            return $orders->filter(function ($order) {
                return strtoupper($order->fulfillmentStatus ?? '') === 'FULFILLED';
            })->values();
        }

        // If status is "UNFULFILLED" or empty, show all except fulfilled orders
        if ($status === 'UNFULFILLED' || empty($status)) {
            return $orders->filter(function ($order) {
                return strtoupper($order->fulfillmentStatus ?? '') !== 'FULFILLED';
            })->values();
        }

        // For any other status value, return all orders
        return $orders;
    }

    /**
     * Get order details by ID
     *
     * @param string $accessToken Customer access token
     * @param string $orderId Order identifier
     * @return OrderDTO
     */
    public function getOrderDetails(string $accessToken, string $orderId): OrderDTO
    {
        try {
            $this->logPerformanceStart('getOrderDetails');

            // First, verify the customer has access to this order by fetching their orders
            $variables = [
                'accessToken' => $accessToken,
                'limit' => 250, // Fetch all orders to find the specific one
                'after' => null,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/orders/get_customer_orders', $variables);

            if (empty($response['data']['customer'])) {
                throw new ShopifyNotFoundException('Customer not found or invalid access token');
            }

            $orders = collect($response['data']['customer']['orders']['edges'] ?? []);
            
            $orderEdge = $orders->first(function ($edge) use ($orderId) {
                return $edge['node']['id'] === $orderId;
            });

            if (!$orderEdge) {
                throw new ShopifyNotFoundException("Order not found: {$orderId}");
            }

            $order = OrderDTO::fromShopifyResponse($orderEdge['node']);

            $this->logPerformanceEnd('getOrderDetails', [
                'order_id' => $orderId,
            ]);

            return $order;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch order details', $e, [
                'order_id' => $orderId,
            ]);
            throw $e;
        }
    }
}
