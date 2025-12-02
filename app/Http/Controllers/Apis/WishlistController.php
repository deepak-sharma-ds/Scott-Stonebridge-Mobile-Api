<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use App\Facades\Shopify;
use App\Traits\ShopifyResponseFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WishlistController extends Controller
{
    use ShopifyResponseFormatter;

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
            $vars = [
                'customerAccessToken' => $this->customerAccessToken,
            ];

            // -----------------------------------------------------
            // Shopify wrapper call
            // -----------------------------------------------------
            $response = Shopify::query(
                'storefront',
                'wishlist/get_customer_wishlist',
                $vars
            );

            $wishlistValue = data_get($response, 'data.customer.metafield.value');

            // Final decoded wishlist (always return array)
            $wishlist = json_decode($wishlistValue ?: "[]", true);

            return $this->success('Wishlist fetched successfully', $wishlist);
        } catch (\Throwable $e) {
            return $this->fail('Failed to fetch wishlist', $e->getMessage());
        }
    }


    /**
     * Add a product to the customer's wishlist (Admin API)
     */
    public function add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|string', // Shopify GID
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation error.', $validator->errors());
        }

        try {
            // Shopify Customer GID (from middleware / decoded token)
            $customerId = $request['shopify_customer_data']['id'] ?? null;
            $productId  = $request->product_id;

            if (!$customerId) {
                return $this->fail('Customer not authenticated');
            }

            // ---------------------------------------------------------
            // 1. Fetch Current Wishlist from Admin API metafield
            // ---------------------------------------------------------
            $currentWishlist = $this->getCurrentWishlistAdmin($customerId);

            // If product already exists in wishlist
            if (in_array($productId, $currentWishlist)) {
                return $this->success('Product already in wishlist');
            }

            // ---------------------------------------------------------
            // 2. Append new product
            // ---------------------------------------------------------
            $currentWishlist[] = $productId;

            // ---------------------------------------------------------
            // 3. Update metafield using your unified helper
            // ---------------------------------------------------------
            $updated = $this->updateWishlistMetafield($customerId, $currentWishlist);

            return $this->success('Product added to wishlist', $updated);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
    }

    /**
     * Remove product from wishlist
     */
    public function remove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|string',
            'product_id'  => 'required|string',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation error.', $validator->errors());
        }

        try {
            $customerId = $request->customer_id;
            $productId  = $request->product_id;

            // ---------------------------------------------------------
            // 1. Fetch Current Wishlist (Admin API)
            // ---------------------------------------------------------
            $currentWishlist = $this->getCurrentWishlistAdmin($customerId);

            // ---------------------------------------------------------
            // 2. Remove item
            // ---------------------------------------------------------
            $updatedWishlist = array_values(
                array_filter($currentWishlist, fn($id) => $id !== $productId)
            );

            // ---------------------------------------------------------
            // 3. Update metafield
            // ---------------------------------------------------------
            $result = $this->updateWishlistMetafield($customerId, $updatedWishlist);

            return $this->success('Product removed from wishlist successfully', $result);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
    }

    /**
     * Fetch wishlist metafield using Admin API (New Standard)
     */
    private function getCurrentWishlistAdmin($customerId)
    {
        try {
            $vars = [
                'id' => $customerId,
            ];

            $response = Shopify::query(
                'admin',
                'wishlist/get_admin_wishlist',
                $vars
            );

            $value = data_get($response, 'data.customer.metafield.value');

            return $value ? json_decode($value, true) : [];
        } catch (\Throwable $e) {
            return []; // fail silently, wishlist is optional
        }
    }


    /**
     * Update wishlist metafield via Admin API (New Standard)
     */
    private function updateWishlistMetafield($customerId, $wishlistArray)
    {
        try {
            $vars = [
                'customerId' => $customerId,
                'value'      => json_encode($wishlistArray),
            ];

            $response = Shopify::query(
                'admin',
                'wishlist/update_admin_wishlist',
                $vars
            );

            // Extract the newly saved metafields
            $metafields = data_get($response, 'data.customerUpdate.customer.metafields.edges', []);

            // Convert edges → node (flatten)
            $clean = array_map(fn($edge) => $edge['node'], $metafields);

            return [
                'updated'    => true,
                'metafields' => $clean,
            ];
        } catch (\Throwable $e) {
            return [
                'updated' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
