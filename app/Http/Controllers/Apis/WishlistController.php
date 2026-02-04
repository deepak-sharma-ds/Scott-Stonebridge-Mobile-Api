<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\WishlistService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WishlistController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WishlistService $wishlistService
    ) {}

    /**
     * Get customer wishlist from metafield
     */
    public function index(Request $request)
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return $this->error('Unauthorized', null, 401);
        }

        try {
            $wishlist = $this->wishlistService->getWishlist($token);
            return $this->success('Wishlist fetched successfully', $wishlist);
        } catch (\Throwable $e) {
            return $this->error('Failed to fetch wishlist', null, 500);
        }
    }

    /**
     * Add a product to the customer's wishlist (Admin API)
     */
    public function add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|string', 
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        // Shopify Customer GID (from middleware / decoded token, or request param legacy)
        // Legacy controller looked at internal 'shopify_customer_data' or similar?
        // Actually, the middleware usually injects data or we decode it.
        // Let's assume we get the ID from the `shopify_customer_data` injected by middleware
        // OR distinct `customer_id` parameter if strictly following legacy behavior?
        // Legacy: $customerId = $request['shopify_customer_data']['id'] ?? null;
        
        $customerId = $request->input('shopify_customer_data.id'); 
        
        if (!$customerId) {
            // Fallback if middleware didn't inject it check request body
             $customerId = $request->input('customer_id');
        }

        if (!$customerId) {
            return $this->error('Customer not authenticated or ID missing', null, 401);
        }

        $result = $this->wishlistService->addToWishlist(
            customerId: $customerId,
            productId: $request->input('product_id')
        );

        if (!$result['success']) {
            return $this->error($result['message'] ?? 'Failed to add to wishlist');
        }

        return $this->success(
            'Product added to wishlist',
            ['updated' => true, 'wishlist' => $result['wishlist'] ?? []]
        );
    }

    /**
     * Remove product from wishlist
     */
    public function remove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id'  => 'required|string',
            // 'customer_id' => 'required|string', // Legacy required this, but we should use Auth
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }
        
        // Similar ID resolution
        $customerId = $request->input('shopify_customer_data.id') ?? $request->input('customer_id');

        if (!$customerId) {
            return $this->error('Customer ID missing', null, 401);
        }

        $result = $this->wishlistService->removeFromWishlist(
            customerId: $customerId, 
            productId: $request->input('product_id')
        );

         if (!$result['success']) {
            return $this->error($result['message'] ?? 'Failed to remove from wishlist');
        }

        return $this->success(
            'Product removed from wishlist successfully',
            ['updated' => true, 'wishlist' => $result['wishlist'] ?? []]
        );
    }
}
