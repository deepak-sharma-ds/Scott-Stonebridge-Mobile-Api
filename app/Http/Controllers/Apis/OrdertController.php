<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrdertController extends Controller
{
    protected $shopify;

    public function __construct(APIShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'sometimes|integer|min:1',
            'after' => 'sometimes|string|nullable',
            'filter' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Something went wrong.',
                'error' => $validator->errors(),
            ], 422);
        }

        $limit = (int) $request->get('limit', 20);
        $after = $request->get('after') ?? null;
        $accessToken = $request->bearerToken();
        $filter = $request->filter;

        $query = <<<'GRAPHQL'
            query customerOrders($accessToken: String!, $limit: Int!, $after: String) {
                customer(customerAccessToken: $accessToken) {
                    id
                    orders(first: $limit, after: $after) {
                        edges {
                            cursor
                            node {
                                id
                                name
                                orderNumber
                                processedAt
                                financialStatus
                                fulfillmentStatus
                                totalPriceV2 { amount currencyCode }
                                subtotalPriceV2 { amount currencyCode }
                                totalTaxV2 { amount currencyCode }
                                lineItems(first: 100) {
                                    edges {
                                    node {
                                        title
                                        quantity
                                        discountedTotalPrice { amount currencyCode }
                                        variant {
                                        id
                                        title
                                        image { url }
                                        product { title handle }
                                        }
                                    }
                                    }
                                }
                                shippingAddress {
                                    name
                                    address1
                                    city
                                    province
                                    country
                                    zip
                                }
                            }
                        }
                        pageInfo {
                            hasNextPage
                        }
                    }
                }
            }
            GRAPHQL;

        $variables = [
            'accessToken' => $accessToken,
            'limit' => $limit,
            'after' => $after,
        ];

        try {
            $data = $this->shopify->storefrontApiRequest($query, $variables);

            // error from helper / GraphQL
            if (isset($data['errors'])) {
                return response()->json([
                    'status' => 500,
                    'message' => 'Failed to fetch orders',
                    'errors' => $data['errors'],
                ], 500);
            }
            $ordersData = data_get($data, 'data.customer.orders') ?? null;
            if (!$ordersData) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No Orders',
                    'data' => []
                ], 404);
            }

            // map edges -> nodes (keep cursor)
            $orders = array_map(function ($edge) {
                $node = $edge['node'];
                return [
                    'id' => $node['id'],
                    'name' => $node['name'],
                    'order_number' => $node['orderNumber'],
                    'processed_at' => $node['processedAt'],
                    'financial_status' => $node['financialStatus'],
                    'fulfillment_status' => $node['fulfillmentStatus'],
                    'total' => $node['totalPriceV2']['amount'] ?? null,
                    'currency' => $node['totalPriceV2']['currencyCode'] ?? null,
                    'subtotal' => $node['subtotalPriceV2']['amount'] ?? null,
                    'tax' => $node['totalTaxV2']['amount'] ?? null,
                    'shipping_address' => $node['shippingAddress'] ?? null,
                    'items' => collect($node['lineItems']['edges'] ?? [])->map(function ($li) {
                        $item = $li['node'];
                        return [
                            'title' => $item['title'],
                            'quantity' => $item['quantity'],
                            'price' => $item['discountedTotalPrice']['amount'] ?? null,
                            'currency' => $item['discountedTotalPrice']['currencyCode'] ?? null,
                            'variant_id' => data_get($item, 'variant.id'),
                            'variant_title' => data_get($item, 'variant.title'),
                            'image' => data_get($item, 'variant.image.url'),
                            'product' => data_get($item, 'variant.product'),
                        ];
                    })->values()->all(),
                ];
            }, $ordersData['edges']);

            $lastCursor = end($ordersData['edges'])['cursor'] ?? null;
            $hasMore = $ordersData['pageInfo']['hasNextPage'] ?? false;

            return response()->json([
                'status' => 200,
                'message' => 'Orders fetched successfully',
                'data' => [
                    'orders' => $orders,
                    'next_cursor' => $hasMore ? $lastCursor : null,
                    'has_more' => $hasMore,
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function getOrderDetails(Request $request)
    {
        $validator = Validator::make(array_merge($request->all()), [
            'order_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $orderId = $request->order_id;

        $query = <<<'GRAPHQL'
            query getOrder($id: ID!) {
                order(id: $id) {
                    id
                    name
                    createdAt
                    processedAt
                    email

                    displayFinancialStatus
                    displayFulfillmentStatus
                    
                    totalPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    subtotalPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    totalShippingPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    totalTaxSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }

                    lineItems(first: 50) {
                        edges {
                            node {
                                title
                                quantity
                                sku
                                discountedTotalSet {
                                    shopMoney {
                                    amount
                                    currencyCode
                                    }
                                }
                                originalTotalSet {
                                    shopMoney {
                                    amount
                                    currencyCode
                                    }
                                }
                                variant {
                                    id
                                    title
                                    image { url }
                                    product { title handle }
                                }
                            }
                        }
                    }

                    customer {
                        id
                        firstName
                        lastName
                        email
                    }
                    shippingAddress {
                        name
                        address1
                        address2
                        city
                        province
                        country
                        zip
                        phone
                    }
                    billingAddress {
                        name
                        address1
                        address2
                        city
                        province
                        country
                        zip
                        phone
                    }
                }
            }
        GRAPHQL;


        $variables = [
            'id' => $orderId,
        ];

        try {
            $data = $this->shopify->adminApiRequest($query, $variables);

            if (isset($data['errors'])) {
                return response()->json([
                    'status' => 500,
                    'message' => 'Failed to fetch order details',
                    'errors' => $data['errors'],
                ], 500);
            }

            $order = data_get($data, 'data.customer.order');

            if (!$order) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Order not found'
                ], 404);
            }

            // 🔹 Format items
            $items = collect($order['lineItems']['edges'] ?? [])->map(function ($edge) {
                $node = $edge['node'];
                return [
                    'title' => $node['title'],
                    'quantity' => $node['quantity'],
                    'sku' => $node['sku'],
                    'price' => data_get($node, 'discountedTotalSet.shopMoney.amount'),
                    'currency' => data_get($node, 'discountedTotalSet.shopMoney.currencyCode'),
                    'variant' => [
                        'id' => data_get($node, 'variant.id'),
                        'title' => data_get($node, 'variant.title'),
                        'image' => data_get($node, 'variant.image.url'),
                        'product' => data_get($node, 'variant.product'),
                    ],
                ];
            });

            // 🔹 Format order summary
            $formatted = [
                'id' => $order['id'],
                'name' => $order['name'],
                'created_at' => $order['createdAt'],
                'processed_at' => $order['processedAt'],
                'email' => $order['email'],
                'financial_status' => $order['financialStatus'],
                'fulfillment_status' => $order['fulfillmentStatus'],
                'subtotal' => data_get($order, 'subtotalPriceSet.shopMoney.amount'),
                'shipping' => data_get($order, 'totalShippingPriceSet.shopMoney.amount'),
                'tax' => data_get($order, 'totalTaxSet.shopMoney.amount'),
                'total' => data_get($order, 'totalPriceSet.shopMoney.amount'),
                'currency' => data_get($order, 'totalPriceSet.shopMoney.currencyCode'),
                'shipping_address' => $order['shippingAddress'],
                'billing_address' => $order['billingAddress'],
                'items' => $items,
            ];

            return response()->json([
                'status' => 200,
                'message' => 'Order details fetched successfully',
                'data' => [
                    'order_details' => $formatted,
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
