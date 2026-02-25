<?php

namespace App\Services\Shopify;

use App\Contracts\Services\HomeServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Home\HomeDTO;
use App\DTOs\Product\CollectionDTO;
use App\DTOs\Product\ProductDTO;
use App\Services\Base\BaseService;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyAuthException;
use App\Traits\CacheWithFallback;
use Illuminate\Support\Facades\Cache;

/**
 * Home Service
 * 
 * Handles home page data retrieval and newsletter subscriptions.
 * Provides featured products, collections, and promotional content.
 * 
 * Requirements: 9.1, 9.6, 9.7, 9.8, 9.9, 9.10
 */
class HomeService extends BaseService implements HomeServiceInterface
{
    use CacheWithFallback;
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient,
        protected AdminApiClientInterface $adminClient
    ) {
        parent::__construct();
    }

    /**
     * Get home page data
     * 
     * Returns featured products, collections, and promotional content
     * for the mobile app home screen.
     * 
     * @param string $featuredTag Tag for featured products collection (default: 'featured')
     * @param int $featuredLimit Number of featured products to fetch (default: 10)
     * @param int $collectionsLimit Number of collections to fetch (default: 6)
     * @return HomeDTO
     */
    public function getHomePageData(
        string $featuredTag = 'featured',
        int $featuredLimit = 10,
        int $collectionsLimit = 6
    ): HomeDTO {
        try {
            $this->logPerformanceStart('getHomePageData');

            $cacheKey = "home:data:{$featuredTag}:{$featuredLimit}:{$collectionsLimit}";
            
            // Check if cache supports tagging
            $homeData = $this->cacheWithFallback(
                $cacheKey,
                900, // 15 minutes
                fn() => $this->fetchHomePageData($featuredTag, $featuredLimit, $collectionsLimit),
                ['home', 'products', 'collections']
            );

            $this->logPerformanceEnd('getHomePageData', [
                'featured_count' => count($homeData['featured_products']),
                'collections_count' => count($homeData['collections']),
            ]);

            return HomeDTO::fromShopifyResponse($homeData);
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch home page data', $e);
            throw $e;
        }
    }

    /**
     * Fetch home page data from Shopify
     * 
     * @param string $featuredTag
     * @param int $featuredLimit
     * @param int $collectionsLimit
     * @return array
     */
    protected function fetchHomePageData(
        string $featuredTag,
        int $featuredLimit,
        int $collectionsLimit
    ): array {
        // Fetch featured products
        $featuredProducts = $this->fetchFeaturedProducts($featuredTag, $featuredLimit);

        // Fetch collections
        $collections = $this->fetchCollections($collectionsLimit);

        return [
            'featured_products' => $featuredProducts,
            'collections' => $collections,
            'banners' => null, // Can be extended to fetch from metafields or custom source
        ];
    }

    /**
     * Fetch featured products from Shopify
     * 
     * @param string $tag Collection handle for featured products
     * @param int $limit Number of products to fetch
     * @return array
     */
    protected function fetchFeaturedProducts(string $tag, int $limit): array
    {
        $variables = [
            'tag' => $tag,
            'limit' => $limit,
            'after' => null,
            'country' => $this->getCurrencyCountryCode(),
        ];

        $response = $this->storefrontClient->queryWithCurrency(
            'storefront/products/get_featured_products',
            $variables
        );

        if (empty($response['data']['collectionByHandle'])) {
            // Return empty array if featured collection doesn't exist
            return [];
        }

        $products = $response['data']['collectionByHandle']['products']['edges'] ?? [];

        return array_map(function ($edge) {
            return [
                'node' => $edge['node'],
                'cursor' => $edge['cursor'] ?? null,
            ];
        }, $products);
    }

    /**
     * Fetch collections from Shopify
     * 
     * @param int $limit Number of collections to fetch
     * @return array
     */
    protected function fetchCollections(int $limit): array
    {
        $variables = [
            'limit' => $limit,
            'after' => null,
        ];

        $response = $this->storefrontClient->query(
            'storefront/collections/get_collections',
            $variables
        );

        $collections = $response['data']['collections']['edges'] ?? [];

        return array_map(function ($edge) {
            return [
                'node' => $edge['node'],
            ];
        }, $collections);
    }

    /**
     * Subscribe customer to newsletter
     * 
     * Updates the customer's acceptsMarketing field to true.
     * Requires customer access token for authentication.
     * 
     * @param string $email Customer email
     * @param string $accessToken Customer access token
     * @return bool
     * @throws ShopifyAuthException
     * @throws ShopifyApiException
     */
    public function subscribeToNewsletter(string $email, string $accessToken): bool
    {
        try {
            $this->logPerformanceStart('subscribeToNewsletter');

            // First, get the customer ID from the access token
            $customerResponse = $this->storefrontClient->query(
                'storefront/customer/get_customer_profile',
                ['customerAccessToken' => $accessToken]
            );

            if (empty($customerResponse['data']['customer'])) {
                throw new ShopifyAuthException('Invalid access token or customer not found');
            }

            $customerId = $customerResponse['data']['customer']['id'];

            // Update customer to accept marketing
            $variables = [
                'id' => $customerId,
                'acceptsMarketing' => true,
            ];

            $updateResponse = $this->adminClient->query(
                'admin/customer/customer_update',
                $variables
            );

            if (!empty($updateResponse['data']['customerUpdate']['userErrors'])) {
                $errors = $updateResponse['data']['customerUpdate']['userErrors'];
                throw new ShopifyApiException('Newsletter subscription failed: ' . json_encode($errors));
            }

            $this->logPerformanceEnd('subscribeToNewsletter', [
                'email' => $email,
                'customer_id' => $customerId,
            ]);

            return true;
        } catch (ShopifyAuthException | ShopifyApiException $e) {
            $this->logErrorWithException('Newsletter subscription failed', $e, ['email' => $email]);
            throw $e;
        } catch (\Exception $e) {
            $this->logErrorWithException('Newsletter subscription failed', $e, ['email' => $email]);
            throw new ShopifyApiException('Failed to subscribe to newsletter: ' . $e->getMessage());
        }
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
