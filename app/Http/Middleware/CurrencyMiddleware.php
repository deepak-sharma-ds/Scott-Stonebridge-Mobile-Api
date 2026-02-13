<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CurrencyMiddleware
 * 
 * Extracts currency from request headers or query params and adds to request context.
 * Validates currency code against supported currencies.
 * 
 * Requirements: 15.2
 */
class CurrencyMiddleware
{
    /**
     * Supported ISO 4217 currency codes
     * 
     * @var array<string>
     */
    protected array $supportedCurrencies = [
        'GBP', // British Pound Sterling
        'USD', // United States Dollar
        'EUR', // Euro
        'CAD', // Canadian Dollar
        'AUD', // Australian Dollar
        'JPY', // Japanese Yen
        'CHF', // Swiss Franc
        'NZD', // New Zealand Dollar
        'SEK', // Swedish Krona
        'DKK', // Danish Krone
        'NOK', // Norwegian Krone
    ];

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

        // Validate currency code against supported currencies
        if (!in_array($currency, $this->supportedCurrencies, true)) {
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
     * Get supported currencies
     * 
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }
}
