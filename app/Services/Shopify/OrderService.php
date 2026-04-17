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
                'orderQuery' => null,
                'lineItemLimit' => $limit,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/orders/get_customer_orders', $variables);

            if (empty($response['data']['customer'])) {
                throw new ShopifyNotFoundException('Customer not found or invalid access token');
            }

            $orders = $this->extractOrderNodes($response)
                ->map(fn($order) => OrderDTO::fromShopifyResponse($order));

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

            $variables = [
                'accessToken' => $accessToken,
                'orderQuery' => $this->normalizeOrderQuery($orderId),
                'limit' => 1,
                'after' => null,
                'lineItemLimit' => 250,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/orders/get_customer_orders', $variables);

            if (empty($response['data']['customer'])) {
                throw new ShopifyNotFoundException('Customer not found or invalid access token');
            }

            $orderData = $this->extractOrderNodes($response)->first();

            if (!$orderData) {
                throw new ShopifyNotFoundException("Order not found: {$orderId}");
            }

            $order = OrderDTO::fromShopifyResponse($orderData);

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

    /**
     * Extract order nodes from a Shopify orders connection.
     */
    protected function extractOrderNodes(array $response): Collection
    {
        $orders = data_get($response, 'data.customer.orders', []);

        if (!empty($orders['edges'])) {
            return collect($orders['edges'])
                ->map(fn($edge) => $edge['node'] ?? null)
                ->filter();
        }

        return collect($orders['nodes'] ?? [])->filter();
    }

    /**
     * Normalize a raw order id or query into Shopify's customer order search syntax.
     */
    protected function normalizeOrderQuery(string $orderId): string
    {
        $orderQuery = trim($orderId);

        if (str_starts_with($orderQuery, 'id:')) {
            return $orderQuery;
        }

        // if (str_starts_with($orderQuery, 'gid://')) {
        //     return "id:{$orderQuery}";
        // }

        return $orderQuery;
    }
}
