<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Http\Controllers\Controller;
use App\Services\Funnels\FunnelTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Storefront → app proxy (/apps/zymox-bundles/*). Signature checked by VerifyAppProxySignature.
 */
class ProxyController extends Controller
{
    /**
     * Funnel events from the widget. Body (JSON, also sent as text/plain by sendBeacon):
     * {"events": [{"funnel_id": 1, "type": "impression|accept|reject|checkout"}]}
     */
    public function events(Request $request, FunnelTrackingService $tracking): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $events = array_slice((array) ($body['events'] ?? []), 0, 50);

        return response()->json(['recorded' => $tracking->recordEvents($events)]);
    }
}
