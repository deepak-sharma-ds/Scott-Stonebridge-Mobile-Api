<?php

namespace App\Http\Controllers\Webhook;
use App\Models\SyncEvent;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
class Klaviyowebhookcontroller extends Controller
{
    public function __construct(protected ShopifyService $shopify) {}

    /**
     * Klaviyo has no built-in outbound "profile unsubscribed" webhook like
     * Shopify does. Instead you build a Flow in Klaviyo:
     *
     *   Trigger: "Unsubscribed from Email Marketing" (or List/Segment based)
     *   Action:  Webhook -> POST https://your-app.com/webhooks/klaviyo/consent-update
     *            Header:  X-Sync-Secret: {your shared secret}
     *            Body:    include profile email and consent state as JSON
     *
     * Klaviyo flow webhooks don't sign requests the way Shopify does, so we
     * use a simple shared-secret header instead - set the same value in
     * both the Flow's webhook headers and your .env.
     */
    public function consentUpdate(Request $request): Response
    {
        if ($request->header('X-Sync-Secret') !== config('services.klaviyo.webhook_secret')) {
            return response('Unauthorized', 401);
        }

        $email = $request->input('email');
        $state = $request->input('state'); // 'unsubscribed' or 'subscribed' - set this in the Flow's webhook body
        $shopifyCustomerId = $request->input('shopify_customer_id'); // pass this through from a custom property if you store it

        if (! $email || ! $state) {
            Log::warning('Klaviyo webhook missing fields', $request->all());
            return response()->noContent();
        }

        if (SyncEvent::wasJustWrittenByUs($email, 'email', $state)) {
            return response()->noContent();
        }

        if (! $shopifyCustomerId) {
            Log::warning('No Shopify customer id on Klaviyo profile - cannot sync back', ['email' => $email]);
            return response()->noContent();
        }

        $success = $this->shopify->updateEmailConsent($shopifyCustomerId, $state === 'subscribed');

        if ($success) {
            SyncEvent::record($email, 'email', $state, 'shopify');
        }

        return response()->noContent();
    }
}
