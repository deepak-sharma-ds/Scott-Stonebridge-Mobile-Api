<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\Shopify\ProcessShopifyConsentUpdateWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyWebhookController extends Controller
{
    public function consentUpdate(Request $request): Response
    {
        if (app()->environment('production')) {
            return response()->noContent();
        }

        ProcessShopifyConsentUpdateWebhookJob::dispatch($request->all())
            ->onQueue((string) config('shopify.queue.marketing_consent_sync', 'klaviyo-marketing-consent-sync'));

        return response()->noContent();
    }
}
