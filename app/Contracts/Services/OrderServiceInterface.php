<?php

namespace App\Contracts\Services;

use App\DTOs\Order\OrderDTO;
use Illuminate\Support\Collection;

interface OrderServiceInterface
{
    /**
     * Get customer orders
     *
     * @param string $accessToken Customer access token
     * @param int $limit Number of orders to fetch
     * @param string|null $cursor Pagination cursor
     * @return Collection Collection of OrderDTO instances
     */
    public function getOrders(string $accessToken, int $limit, ?string $cursor): Collection;

    /**
     * Get order details by ID
     *
     * @param string $accessToken Customer access token
     * @param string $orderId Order identifier
     * @return OrderDTO
     */
    public function getOrderDetails(string $accessToken, string $orderId): OrderDTO;
}
