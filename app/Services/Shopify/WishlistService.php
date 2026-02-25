<?php

namespace App\Services\Shopify;

use App\Contracts\Services\WishlistServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Product\ProductDTO;
use App\DTOs\Wishlist\WishlistDTO;
use App\DTOs\Wishlist\WishlistItemDTO;
use App\Services\Base\BaseService;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyAuthException;
use App\Exceptions\ShopifyNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * Wishlist Service
 * 
 * Handles wishlist management using Shopify customer metafields.
 * Provides CRUD operations for customer wishlist items.
 * 
 * Requirements: 9.3, 9.6, 9.7, 9.8, 9.9, 9.10
 */
class WishlistService extends BaseService implements WishlistServiceInterface
{
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient,
        protected AdminApiClientInterface $adminClient
    ) {
        parent::__construct();
    }

    /**
     * Get customer wishlist
     * 
     * Retrieves the customer's wishlist from metafields and enriches
     * with full product details.
     * 
     * @param string $accessToken Customer access token
     * @return WishlistDTO
     * @throws ShopifyAuthException
     */
    public function getWishlist(string $accessToken): WishlistDTO
    {
        try {
            $this->logPerformanceStart('getWishlist');

            // Get customer profile with wishlist metafield
            $customerResponse = $this->storefrontClient->query(
                'storefront/wishlist/get_customer_wishlist',
                ['customerAccessToken' => $accessToken]
            );

            if (empty($customerResponse['data']['customer'])) {
                throw new ShopifyAuthException('Invalid access token or customer not found');
            }

            $customer = $customerResponse['data']['customer'];
            $customerId = $customer['id'];

            // Parse wishlist items from metafield
            $wishlistMetafield = $customer['metafield']['value'] ?? null;
            $productIds = $wishlistMetafield ? json_decode($wishlistMetafield, true) : [];

            // Fetch full product details for each wishlist item
            $items = [];
            if (!empty($productIds) && is_array($productIds)) {
                $items = $this->fetchWishlistProducts($productIds);
            }

            $wishlist = WishlistDTO::fromShopifyResponse([
                'customer_id' => $customerId,
                'items' => $items,
            ]);

            $this->logPerformanceEnd('getWishlist', [
                'customer_id' => $customerId,
                'items_count' => count($items),
            ]);

            return $wishlist;
        } catch (ShopifyAuthException $e) {
            $this->logErrorWithException('Failed to fetch wishlist', $e);
            throw $e;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch wishlist', $e);
            throw new ShopifyApiException('Failed to fetch wishlist: ' . $e->getMessage());
        }
    }

    /**
     * Add item to wishlist
     * 
     * Adds a product to the customer's wishlist metafield.
     * Prevents duplicate products from being added.
     * 
     * @param string $accessToken Customer access token
     * @param string $productId Shopify product ID
     * @return WishlistDTO
     * @throws ShopifyAuthException
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    public function addItem(string $accessToken, string $productId): WishlistDTO
    {
        try {
            $this->logPerformanceStart('addWishlistItem');

            // Get current wishlist
            $currentWishlist = $this->getWishlist($accessToken);

            // Check if product already exists
            if ($currentWishlist->hasProduct($productId)) {
                $this->logPerformanceEnd('addWishlistItem', [
                    'product_id' => $productId,
                    'already_exists' => true,
                ]);
                return $currentWishlist;
            }

            // Verify product exists
            $this->verifyProductExists($productId);

            // Add product to wishlist
            $productIds = $currentWishlist->getProductIds();
            $productIds[] = $productId;

            // Update wishlist metafield
            $this->updateWishlistMetafield($currentWishlist->customerId, $productIds);

            // Fetch updated wishlist
            $updatedWishlist = $this->getWishlist($accessToken);

            $this->logPerformanceEnd('addWishlistItem', [
                'product_id' => $productId,
                'items_count' => $updatedWishlist->getTotalItems(),
            ]);

            return $updatedWishlist;
        } catch (ShopifyAuthException | ShopifyNotFoundException | ShopifyApiException $e) {
            $this->logErrorWithException('Failed to add item to wishlist', $e, ['product_id' => $productId]);
            throw $e;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to add item to wishlist', $e, ['product_id' => $productId]);
            throw new ShopifyApiException('Failed to add item to wishlist: ' . $e->getMessage());
        }
    }

    /**
     * Remove item from wishlist
     * 
     * Removes a product from the customer's wishlist metafield.
     * 
     * @param string $accessToken Customer access token
     * @param string $productId Shopify product ID
     * @return WishlistDTO
     * @throws ShopifyAuthException
     * @throws ShopifyApiException
     */
    public function removeItem(string $accessToken, string $productId): WishlistDTO
    {
        try {
            $this->logPerformanceStart('removeWishlistItem');

            // Get current wishlist
            $currentWishlist = $this->getWishlist($accessToken);

            // Remove product from list
            $productIds = array_filter(
                $currentWishlist->getProductIds(),
                fn($id) => $id !== $productId
            );

            // Update wishlist metafield
            $this->updateWishlistMetafield($currentWishlist->customerId, array_values($productIds));

            // Fetch updated wishlist
            $updatedWishlist = $this->getWishlist($accessToken);

            $this->logPerformanceEnd('removeWishlistItem', [
                'product_id' => $productId,
                'items_count' => $updatedWishlist->getTotalItems(),
            ]);

            return $updatedWishlist;
        } catch (ShopifyAuthException | ShopifyApiException $e) {
            $this->logErrorWithException('Failed to remove item from wishlist', $e, ['product_id' => $productId]);
            throw $e;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to remove item from wishlist', $e, ['product_id' => $productId]);
            throw new ShopifyApiException('Failed to remove item from wishlist: ' . $e->getMessage());
        }
    }

    /**
     * Fetch full product details for wishlist items
     * 
     * @param array $productIds Array of Shopify product IDs
     * @return array Array of wishlist item data
     */
    protected function fetchWishlistProducts(array $productIds): array
    {
        $items = [];

        foreach ($productIds as $productId) {
            try {
                // Extract handle from product ID if needed
                $handle = $this->extractHandleFromId($productId);
                
                // Fetch product details
                $response = $this->storefrontClient->queryWithCurrency(
                    'storefront/product/get_product_details',
                    [
                        'handle' => $handle,
                        'country' => $this->getCurrencyCountryCode(),
                    ]
                );

                if (!empty($response['data']['productByHandle'])) {
                    $product = $response['data']['productByHandle'];
                    
                    $items[] = [
                        'product_id' => $product['id'],
                        'product' => $product,
                        'added_at' => now()->toIso8601String(),
                    ];
                }
            } catch (\Exception $e) {
                // Log error but continue with other products
                $this->logErrorWithException('Failed to fetch wishlist product', $e, ['product_id' => $productId]);
            }
        }

        return $items;
    }

    /**
     * Update wishlist metafield
     * 
     * @param string $customerId Shopify customer ID
     * @param array $productIds Array of product IDs
     * @throws ShopifyApiException
     */
    protected function updateWishlistMetafield(string $customerId, array $productIds): void
    {
        $variables = [
            'customerId' => $customerId,
            'value' => json_encode($productIds),
        ];

        $response = $this->adminClient->query(
            'admin/wishlist/update_admin_wishlist',
            $variables
        );

        if (!empty($response['data']['customerUpdate']['userErrors'])) {
            $errors = $response['data']['customerUpdate']['userErrors'];
            throw new ShopifyApiException('Failed to update wishlist: ' . json_encode($errors));
        }
    }

    /**
     * Verify that a product exists
     * 
     * @param string $productId Shopify product ID
     * @throws ShopifyNotFoundException
     */
    protected function verifyProductExists(string $productId): void
    {
        try {
            $handle = $this->extractHandleFromId($productId);
            
            $response = $this->storefrontClient->query(
                'storefront/product/get_product_details',
                ['handle' => $handle]
            );

            if (empty($response['data']['productByHandle'])) {
                throw new ShopifyNotFoundException("Product not found: {$productId}");
            }
        } catch (ShopifyNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ShopifyNotFoundException("Product not found: {$productId}");
        }
    }

    /**
     * Extract product handle from Shopify ID
     * 
     * Shopify IDs are in format: gid://shopify/Product/123456789
     * This method extracts the numeric ID or handle.
     * 
     * @param string $productId Shopify product ID or handle
     * @return string Product handle or ID
     */
    protected function extractHandleFromId(string $productId): string
    {
        // If it's already a handle (no gid://), return as is
        if (!str_starts_with($productId, 'gid://')) {
            return $productId;
        }

        // Extract numeric ID from GID
        $parts = explode('/', $productId);
        return end($parts);
    }

    /**
     * Get currency country code from request context
     * 
     * @return string
     */
    protected function getCurrencyCountryCode(): string
    {
        $currency = request()->header('X-Currency') 
            ?? request()->get('currency')
            ?? config('shopify.currency', 'GBP');

        // Map currency to country code
        $currencyMap = [
            'GBP' => 'GB',
            'USD' => 'US',
            'EUR' => 'DE',
            'CAD' => 'CA',
            'AUD' => 'AU',
        ];

        return $currencyMap[strtoupper($currency)] ?? 'GB';
    }
}
