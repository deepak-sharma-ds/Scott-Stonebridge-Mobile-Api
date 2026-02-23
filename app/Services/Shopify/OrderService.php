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
     * @return Collection Collection of OrderDTO instances
     */
    public function getOrders(string $accessToken, int $limit, ?string $cursor): Collection
    {
        try {
            $this->logPerformanceStart('getOrders');

            $variables = [
                'accessToken' => $accessToken,
                'limit' => $limit,
                'after' => $cursor,
            ];

            $response = $this->storefrontClient->query('storefront/orders/get_customer_orders', $variables);

            if (empty($response['data']['customer'])) {
                throw new ShopifyNotFoundException('Customer not found or invalid access token');
            }

            $orders = collect($response['data']['customer']['orders']['edges'] ?? [])
                ->map(fn($edge) => OrderDTO::fromShopifyResponse($edge['node']));

            $this->logPerformanceEnd('getOrders', [
                'count' => $orders->count(),
                'has_next_page' => $response['data']['customer']['orders']['pageInfo']['hasNextPage'] ?? false,
            ]);

            return $orders;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch customer orders', $e, [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);
            throw $e;
        }
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
            ];

            $response = $this->storefrontClient->query('storefront/orders/get_customer_orders', $variables);

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
