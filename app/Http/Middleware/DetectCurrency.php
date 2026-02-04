<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DetectCurrency
{
    /**
     * Country to currency mapping
     */
    private const CURRENCY_MAP = [
        'US' => 'USD',
        'GB' => 'GBP',
        'CA' => 'CAD',
        'AU' => 'AUD',
        'IN' => 'INR',
        'EU' => 'EUR',
        'DE' => 'EUR',
        'FR' => 'EUR',
        'IT' => 'EUR',
        'ES' => 'EUR',
    ];
    
    public function handle(Request $request, Closure $next)
    {
        // Priority: 1. Header, 2. Query param, 3. Default
        $countryCode = $request->header('X-Country-Code')
            ?? $request->query('country')
            ?? 'US';
        
        // Normalize to uppercase
        $countryCode = strtoupper($countryCode);
        
        // Get currency for country
        $currencyCode = self::CURRENCY_MAP[$countryCode] ?? 'USD';
        
        // Store in request for use in controllers/services
        $request->merge([
            'detected_country' => $countryCode,
            'detected_currency' => $currencyCode,
        ]);
        
        return $next($request);
    }
}
