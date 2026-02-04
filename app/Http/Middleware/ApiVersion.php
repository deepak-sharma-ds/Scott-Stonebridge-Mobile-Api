<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiVersion
{
    private const SUPPORTED_VERSIONS = ['v1', 'v2'];
    private const DEFAULT_VERSION = 'v1';
    private const DEPRECATED_VERSIONS = [
        // 'v1' => '2026-06-01', // Uncomment when v1 is deprecated
    ];
    
    public function handle(Request $request, Closure $next)
    {
        // Extract version from URI
        $version = $this->extractVersion($request);
        
        // Validate version
        if (!in_array($version, self::SUPPORTED_VERSIONS)) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported API version',
                'supported_versions' => self::SUPPORTED_VERSIONS,
            ], 400);
        }
        
        // Add version to request
        $request->merge(['api_version' => $version]);
        
        // Add deprecation warning header if applicable
        if (isset(self::DEPRECATED_VERSIONS[$version])) {
            $deprecationDate = self::DEPRECATED_VERSIONS[$version];
            $response = $next($request);
            
            $response->headers->set(
                'X-API-Deprecation-Warning',
                "This API version will be deprecated on {$deprecationDate}. Please upgrade to the latest version."
            );
            
            $response->headers->set('X-API-Deprecation-Date', $deprecationDate);
            $response->headers->set('X-API-Latest-Version', 'v2');
            
            return $response;
        }
        
        return $next($request);
    }
    
    private function extractVersion(Request $request): string
    {
        // Extract from URI: /api/v1/products -> v1
        $segments = $request->segments();
        
        foreach ($segments as $segment) {
            if (preg_match('/^v\d+$/', $segment)) {
                return $segment;
            }
        }
        
        return self::DEFAULT_VERSION;
    }
}
