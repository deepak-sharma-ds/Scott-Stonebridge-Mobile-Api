<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Shopify\ShopifyAdapterInterface;
use App\Services\Shopify\GraphQLLoaderService;
use Illuminate\Support\Facades\Log;

class WishlistService
{
    public function __construct(
        private readonly ShopifyAdapterInterface $adapter,
        private readonly GraphQLLoaderService $queryLoader
    ) {}

    /**
     * Get Wishlist using Storefront API (for read-only)
     */
    public function getWishlist(string $customerAccessToken): array
    {
        $query = $this->queryLoader->load('storefront/wishlist/get_customer_wishlist');
        $variables = ['customerAccessToken' => $customerAccessToken];

        $response = $this->adapter->storefrontQuery($query, $variables);
        
        $json = data_get($response, 'customer.metafield.value');
        
        return $json ? json_decode($json, true) : [];
    }

    /**
     * Get Wishlist using Admin API (for internal logic)
     */
    public function getWishlistAdmin(string $customerId): array
    {
        $query = $this->queryLoader->load('admin/wishlist/get_admin_wishlist');
        $variables = ['id' => $customerId];

        try {
            $response = $this->adapter->adminQuery($query, $variables);
            $json = data_get($response, 'customer.metafield.value');
            
            return $json ? json_decode($json, true) : [];
        } catch (\Throwable $e) {
            Log::error('Failed to fetch admin wishlist', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Add product to wishlist (Admin API)
     */
    public function addToWishlist(string $customerId, string $productId): array
    {
        $current = $this->getWishlistAdmin($customerId);

        if (in_array($productId, $current)) {
            return [
                'success' => true,
                'message' => 'Product already in wishlist',
                'wishlist' => $current
            ];
        }

        $current[] = $productId;
        
        return $this->updateWishlistMetafield($customerId, $current);
    }

    /**
     * Remove product from wishlist (Admin API)
     */
    public function removeFromWishlist(string $customerId, string $productId): array
    {
        $current = $this->getWishlistAdmin($customerId);
        
        // Filter out the product ID
        $updated = array_values(array_filter($current, fn($id) => $id !== $productId));
        
        if (count($current) === count($updated)) {
             return [
                'success' => true,
                'message' => 'Product not found in wishlist',
                'wishlist' => $current
            ];
        }

        return $this->updateWishlistMetafield($customerId, $updated);
    }

    /**
     * Update the metafile value
     */
    private function updateWishlistMetafield(string $customerId, array $wishlist): array
    {
        $query = $this->queryLoader->load('admin/wishlist/update_admin_wishlist');
        
        $variables = [
            'customerId' => $customerId,
            'value' => json_encode($wishlist)
        ];

        try {
            $response = $this->adapter->adminQuery($query, $variables);
            
            // Check for user errors
            $errors = data_get($response, 'customerUpdate.userErrors');
            if (!empty($errors)) {
                return [
                    'success' => false,
                    'message' => 'Shopify Error: ' . ($errors[0]['message'] ?? 'Unknown'),
                    'errors' => $errors
                ];
            }

            return [
                'success' => true,
                'message' => 'Wishlist updated successfully',
                'wishlist' => $wishlist
            ];
        } catch (\Throwable $e) {
             return [
                'success' => false,
                'message' => 'System Error: ' . $e->getMessage()
            ];
        }
    }
}
