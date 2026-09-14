<?php

namespace App\Http\Controllers\Webhook;
use App\Models\SyncEvent;
use App\Services\KlaviyoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
class ShopifyWebhookController extends Controller
{
     public function consentUpdate(
        Request $request,
        KlaviyoService $klaviyo
         ): Response {
        $payload = $request->all();

        $customerId = $payload['customer_id'] ?? null;
        $email = $payload['email_address'] ?? null;
        $state = $payload['email_marketing_consent']['state'] ?? null;

        Log::info('SHOPIFY EMAIL CONSENT WEBHOOK', [
            'customer_id' => $customerId,
            'email' => $email,
            'state' => $state,
        ]);

        if (! $email || ! $state) {
            Log::warning('Shopify webhook missing email or consent state', [
                'payload' => $payload,
            ]);

            return response()->noContent();
        }

        if (SyncEvent::wasJustWrittenByUs($email, 'email', $state)) {
            Log::info('Skipping Shopify → Klaviyo sync because it was already written by us', [
                'email' => $email,
                'state' => $state,
            ]);

            return response()->noContent();
        }

        if ($state === 'subscribed') {
            $success = $klaviyo->subscribe($email, 'email');
        } elseif ($state === 'unsubscribed') {
            $success = $klaviyo->unsubscribe($email, 'email');
        } else {
            Log::warning('Unknown Shopify email consent state', [
                'email' => $email,
                'state' => $state,
            ]);

            return response()->noContent();
        }

        Log::info('Shopify → Klaviyo email consent sync', [
            'customer_id' => $customerId,
            'email' => $email,
            'state' => $state,
            'success' => $success,
        ]);

        return response()->noContent();
    }
}
