<?php

namespace App\Http\Middleware;

use App\Contracts\Services\ShopServiceInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CurrencyMiddleware
 * 
 * Extracts currency from request headers or query params and adds to request context.
 * Validates currency code against Shopify's supported currencies dynamically.
 * 
 * Requirements: 15.2
 */
class CurrencyMiddleware
{
    /**
     * Fallback currencies if API fails
     * 
     * @var array<string>
     */
    protected array $fallbackCurrencies = [
        'GBP', 'USD', 'EUR', 'CAD', 'AUD',
    ];

    /**
     * Constructor
     * 
     * @param ShopServiceInterface $shopService
     */
    public function __construct(
        protected ShopServiceInterface $shopService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract currency from multiple sources (priority order):
        // 1. Request header (X-Currency)
        // 2. Query parameter (currency)
        // 3. Cookie (currency)
        // 4. Default from config
        $currency = $request->header('X-Currency')
            ?? $request->query('currency')
            ?? $request->cookie('currency')
            ?? config('shopify.currency', 'GBP');

        // Normalize to uppercase
        $currency = strtoupper(trim($currency));

        // Validate currency code against Shopify's supported currencies
        if (!$this->isCurrencySupported($currency)) {
            // Fall back to default if invalid
            $currency = config('shopify.currency', 'GBP');
        }

        // Add currency to request context for use in controllers/services
        $request->attributes->set('currency', $currency);

        // Also make it available via request merge for easier access
        $request->merge(['currency' => $currency]);

        // Save globally for backend use (backward compatibility)
        config(['app.currency' => $currency]);

        return $next($request);
    }

    /**
     * Check if currency is supported
     * 
     * Fetches from Shopify API with fallback to static list
     * 
     * @param string $currency
     * @return bool
     */
    protected function isCurrencySupported(string $currency): bool
    {
        try {
            // Try to get from Shopify API (cached for 24 hours)
            return $this->shopService->isCurrencySupported($currency);
        } catch (\Exception $e) {
            // Fallback to static list if API fails
            return in_array($currency, $this->fallbackCurrencies, true);
        }
    }

    /**
     * Get supported currencies
     * 
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        try {
            return $this->shopService->getSupportedCurrencies();
        } catch (\Exception $e) {
            return $this->fallbackCurrencies;
        }
    }
}
