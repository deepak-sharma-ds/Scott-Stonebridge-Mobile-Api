<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use App\Facades\Shopify;
use App\Traits\ShopifyResponseFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrdertController extends Controller
{
    use ShopifyResponseFormatter;

    protected $shopify;

    public function __construct(APIShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    /**
     * Show all orders for authenticated customer
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit'  => 'sometimes|integer|min:1|max:250',
            'after'  => 'sometimes|string|nullable',
            'filter' => 'required|string',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation error.', $validator->errors());
        }

        try {
            $limit  = (int) $request->get('limit', 20);
            $after  = $request->get('after');
            $token  = $request->bearerToken();

            $vars = [
                'accessToken' => $token,
                'limit'       => $limit,
                'after'       => $after,
            ];

            // ---------------------------------------------------
            // Call Shopify using unified wrapper
            // ---------------------------------------------------
            $response = Shopify::query(
                'storefront',
                'orders/get_customer_orders',
                $vars
            );
            dd($response);
            $ordersNode = data_get($response, 'data.customer.orders');

            if (!$ordersNode) {
                return $this->success('No Orders', [
                    'orders'      => [],
                    'next_cursor' => null,
                    'has_more'    => false,
                ]);
            }

            // ---------------------------------------------------
            // Pagination (Top Level)
            // ---------------------------------------------------
            $parsed = $this->parseConnection($ordersNode, 'orders');

            // ---------------------------------------------------
            // Refine nested edges (lineItems → variants → images)
            // ---------------------------------------------------
            $parsed['orders'] = $this->refineNestedEdges($parsed['orders']);

            return $this->success('Orders fetched successfully', $parsed);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
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
            return $this->fail('Validation error.', $validator->errors());
        }

        try {
            $vars = [
                'id' => $request->order_id,
            ];

            // -----------------------------------------------------
            // Run GraphQL via new wrapper
            // -----------------------------------------------------
            $response = Shopify::query(
                'admin',
                'orders/get_order_details',
                $vars
            );

            $order = data_get($response, 'data.order');

            if (!$order) {
                return $this->fail('Order not found');
            }

            // -----------------------------------------------------
            // Refine Nested Edges (lineItems, variants, images, etc.)
            // -----------------------------------------------------
            $order = $this->refineNestedEdges($order);

            return $this->success('Order details fetched successfully', [
                'order_details' => $order,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
    }
}
