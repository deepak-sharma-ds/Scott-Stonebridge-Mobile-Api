<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WishlistController extends Controller
{
    protected $shopify;
    protected $customerAccessToken;

    public function __construct(APIShopifyService $shopify, Request $request)
    {
        $this->shopify = $shopify;
        $this->customerAccessToken = $request->bearerToken();
    }

    /**
     * Get customer wishlist from metafield
     */
    public function index(Request $request)
    {
        try {
            $customerAccessToken = $this->customerAccessToken;

            $query = <<<'GRAPHQL'
                query customerWishlist($customerAccessToken: String!) {
                    customer(customerAccessToken: $customerAccessToken) {
                        id
                        metafield(namespace: "wishlist", key: "items") {
                            value
                        }
                    }
                }
            GRAPHQL;

            $variables = [
                "customerAccessToken" => $customerAccessToken
            ];

            $data = $this->shopify->storefrontApiRequest($query, $variables);
            dd($data);

            if (isset($data['errors'])) {
                return response()->json([
                    'status' => 500,
                    'message' => 'Failed to fetch wishlist',
                    'errors' => $data['errors'],
                ], 500);
            }

            $wishlist = data_get($data, 'data.customer.metafield.value');

            return response()->json([
                'status' => 200,
                'message' => 'Wishlist fetched successfully',
                'data' => json_decode($wishlist ?: "[]"),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a product to wishlist (Admin API)
     */
    public function add(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                // 'customer_id' => ['required', 'string'],   // Shopify GID
                'product_id'  => ['required', 'string'],   // Shopify GID
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // $customerId = $request->customer_id;
            $customerId = $request['shopify_customer_data']['id'] ?? null;
            $productId = $request->product_id;
            // dd($customerId, $productId);

            // 1. Fetch current wishlist via Admin (metafields)
            $currentWishlist = $this->getCurrentWishlistAdmin($customerId);

            if (in_array($productId, $currentWishlist)) {
                return response()->json([
                    'status' => 200,
                    'message' => 'Product already in wishlist',
                ], 200);
            }

            $currentWishlist[] = $productId;

            return $this->updateWishlistMetafield($customerId, $currentWishlist);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove product from wishlist
     */
    public function remove(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'customer_id' => ['required', 'string'],
                'product_id'  => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = $request->customer_id;
            $productId = $request->product_id;

            $currentWishlist = $this->getCurrentWishlistAdmin($customerId);

            $updated = array_values(array_filter($currentWishlist, fn($id) => $id !== $productId));

            return $this->updateWishlistMetafield($customerId, $updated);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch wishlist metafield using Admin API
     */
    private function getCurrentWishlistAdmin($customerId)
    {
        $query = <<<'GRAPHQL'
            query getWishlist($id: ID!) {
                customer(id: $id) {
                    id
                    metafield(namespace: "wishlist", key: "items") {
                        value
                    }
                }
            }
        GRAPHQL;

        $data = $this->shopify->adminApiRequest($query, ["id" => $customerId]);

        $value = data_get($data, 'data.customer.metafield.value');

        return $value ? json_decode($value, true) : [];
    }

    /**
     * Update wishlist metafield via Admin API
     */
    private function updateWishlistMetafield($customerId, $wishlistArray)
    {
        $query = <<<'GRAPHQL'
            mutation updateWishlist($customerId: ID!, $value: String!) {
                customerUpdate(
                    input: {
                        id: $customerId
                        metafields: [
                            {
                                namespace: "wishlist"
                                key: "items"
                                type: "json"
                                value: $value
                            }
                        ]
                    }
                ) {
                    customer {
                        id
                        metafields(first: 10, namespace: "wishlist") {
                            edges {
                                node {
                                    key
                                    value
                                }
                            }
                        }
                    }
                    userErrors {
                        message
                    }
                }
            }
        GRAPHQL;

        $variables = [
            "customerId" => $customerId,
            "value" => json_encode($wishlistArray),
        ];

        return $this->shopify->adminApiRequest($query, $variables);
    }
}
