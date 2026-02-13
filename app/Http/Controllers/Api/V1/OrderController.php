<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\OrderServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Resources\Order\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Order Controller (v1)
 * 
 * Handles order-related API endpoints.
 * Requires authentication via customer access token.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 2.1, 2.2, 5.4, 11.6
 */
class OrderController extends BaseApiController
{
    public function __construct(
        protected OrderServiceInterface $orderService
    ) {}

    /**
     * Get customer orders
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $limit = (int) $request->input('limit', 20);
            $cursor = $request->input('cursor');

            $orders = $this->orderService->getOrders($accessToken, $limit, $cursor);

            return $this->success(
                'Orders fetched successfully',
                [
                    'orders' => OrderResource::collection($orders),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (\Exception $e) {
            return $this->error(
                'Failed to fetch orders',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get order detail
     * 
     * @param string $orderId
     * @param Request $request
     * @return JsonResponse
     */
    public function show(string $orderId, Request $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $order = $this->orderService->getOrderDetails($accessToken, $orderId);

            return $this->success(
                'Order fetched successfully',
                [
                    'order' => new OrderResource($order),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Exception $e) {
            return $this->error(
                'Failed to fetch order',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}

