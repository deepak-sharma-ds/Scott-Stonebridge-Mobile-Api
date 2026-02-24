<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Wishlist\AddWishlistItemRequest;
use App\Http\Resources\Wishlist\WishlistResource;
use App\Services\Shopify\WishlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Wishlist Controller (v1)
 * 
 * Handles wishlist management endpoints.
 * Provides CRUD operations for customer wishlist items.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 9.3, 9.6, 9.7, 9.8, 9.9, 9.10
 */
class WishlistController extends BaseApiController
{
    public function __construct(
        protected WishlistService $wishlistService
    ) {}

    /**
     * Get customer wishlist
     * 
     * Returns the authenticated customer's wishlist with
     * full product details for each item.
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

            $wishlist = $this->wishlistService->getWishlist($accessToken);

            return $this->success(
                'Wishlist retrieved successfully',
                new WishlistResource($wishlist)
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Wishlist retrieval failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to fetch wishlist', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch wishlist',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Add item to wishlist
     * 
     * Adds a product to the authenticated customer's wishlist.
     * Prevents duplicate products from being added.
     * 
     * @param AddWishlistItemRequest $request
     * @return JsonResponse
     */
    public function store(AddWishlistItemRequest $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $wishlist = $this->wishlistService->addItem(
                $accessToken,
                $request->validated('product_id')
            );

            return $this->success(
                'Product added to wishlist',
                new WishlistResource($wishlist),
                [],
                201
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Add to wishlist failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'product_id' => $request->validated('product_id'),
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::warning('Product not found', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'product_id' => $request->validated('product_id'),
            ]);

            return $this->notFound($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            Log::error('Add to wishlist failed - API error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'product_id' => $request->validated('product_id'),
            ]);

            return $this->error(
                'Failed to add product to wishlist',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            Log::error('Failed to add product to wishlist', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'product_id' => $request->validated('product_id'),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to add product to wishlist',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Remove item from wishlist
     * 
     * Removes a product from the authenticated customer's wishlist.
     * 
     * @param Request $request
     * @param string $productId
     * @return JsonResponse
     */
    public function destroy(Request $request, string $productId): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $wishlist = $this->wishlistService->removeItem($accessToken, $productId);

            return $this->success(
                'Product removed from wishlist',
                new WishlistResource($wishlist)
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Remove from wishlist failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'product_id' => $productId,
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            Log::error('Remove from wishlist failed - API error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'product_id' => $productId,
            ]);

            return $this->error(
                'Failed to remove product from wishlist',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            Log::error('Failed to remove product from wishlist', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'product_id' => $productId,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to remove product from wishlist',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
