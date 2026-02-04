<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource; // Placeholder if we need OrderResource later
use App\Services\Shopify\StorefrontService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StorefrontService $storefrontService
    ) {}

    /**
     * Show all orders for authenticated customer
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit'  => 'sometimes|integer|min:1|max:250',
            'after'  => 'sometimes|string|nullable',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $token = $request->bearerToken();
        if (!$token) {
            return $this->error('Unauthorized', null, 401);
        }

        $limit = (int) $request->input('limit', 20);
        $cursor = $request->input('after');

        // Service returns a Collection of DTOs.
        // Pagination logic needs to be robust. 
        // For this phase, we implemented simple collection return.
        // We might need to adjust service `getOrders` to return cursor info.
        // Let's assume for now we just return the list.
        $orders = $this->storefrontService->getOrders($token, $limit, $cursor);

        return $this->success(
            'Orders fetched successfully',
            [
                'orders' => $orders,
                'has_more' => $orders->count() === $limit, // Approximate check
                'next_cursor' => null // We'll need to enhance Service to return this
            ]
        );
    }

    /**
     * Get Order Details by ID
     */
    public function getOrderDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $order = $this->storefrontService->getOrder($request->order_id);

        if (!$order) {
            return $this->error('Order not found', null, 404);
        }

        return $this->success(
            'Order details fetched successfully',
            ['order_details' => $order]
        );
    }
}
