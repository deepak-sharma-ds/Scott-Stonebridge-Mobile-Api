<?php

namespace App\Services\Shopify;

use App\Contracts\Services\NavigationServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Navigation\MenuDTO;
use App\Exceptions\ShopifyNotFoundException;
use App\Services\Base\BaseService;
use App\Traits\CacheWithFallback;

/**
 * Navigation Service
 * 
 * Handles navigation/menu operations from Shopify
 */
class NavigationService extends BaseService implements NavigationServiceInterface
{
    use CacheWithFallback;

    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient
    ) {
        parent::__construct();
    }

    /**
     * Get menu by handle
     * 
     * Fetches menu from Shopify and caches for 6 hours.
     * Menus don't change frequently, so longer cache is acceptable.
     * 
     * @param string $handle Menu handle (e.g., 'main-menu', 'footer')
     * @return MenuDTO
     * @throws ShopifyNotFoundException
     */
    public function getMenu(string $handle): MenuDTO
    {
        try {
            $this->logPerformanceStart('getMenu');

            $menu = $this->cacheWithFallback(
                "navigation:menu:{$handle}",
                21600, // 6 hours
                fn() => $this->fetchMenu($handle),
                ['navigation', 'menu', $handle]
            );

            $this->logPerformanceEnd('getMenu', [
                'handle' => $handle,
                'items_count' => count($menu->items),
            ]);

            return $menu;
        } catch (ShopifyNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch menu', $e, ['handle' => $handle]);
            throw $e;
        }
    }

    /**
     * Fetch menu from Shopify API
     * 
     * @param string $handle
     * @return MenuDTO
     * @throws ShopifyNotFoundException
     */
    protected function fetchMenu(string $handle): MenuDTO
    {
        $response = $this->storefrontClient->query('storefront/navigation/get_menu', [
            'handle' => $handle,
        ]);

        if (empty($response['data']['menu'])) {
            throw new ShopifyNotFoundException("Menu with handle '{$handle}' not found");
        }

        return MenuDTO::fromShopifyResponse($response['data']['menu']);
    }

    /**
     * Clear navigation cache
     * 
     * @return void
     */
    public function clearNavigationCache(): void
    {
        $this->forgetCacheWithFallback(['navigation']);
        
        // Clear specific menu caches if needed
        $commonHandles = ['main-menu', 'footer', 'mobile-menu'];
        foreach ($commonHandles as $handle) {
            \Illuminate\Support\Facades\Cache::forget("navigation:menu:{$handle}");
        }
    }
}
