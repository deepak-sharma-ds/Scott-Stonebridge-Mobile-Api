<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrency
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get currency from query param first, then cookie, then default
        $currency = $request->get('currency')
            ?? $request->cookie('currency')
            ?? 'GBP';

        // Example of Cookie Frontend: document.cookie = `currency=${currency}; path=/; max-age=${60*60*24*30}`; // 30 days

        // Save globally for backend use
        config(['app.currency' => $currency]);

        return $next($request);
    }
}
