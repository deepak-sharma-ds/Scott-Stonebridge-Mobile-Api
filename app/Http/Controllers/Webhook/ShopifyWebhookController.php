<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
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
        $email = $payload['email_address'] ?? $payload['email'] ?? null;
        $phone = $payload['phone'] ?? null;

        $consentUpdates = [
            [
                'identifier' => $email,
                'channel' => 'email',
                'state' => $payload['email_marketing_consent']['state'] ?? null,
            ],
            [
                'identifier' => $phone,
                'channel' => 'sms',
                'state' => $payload['sms_marketing_consent']['state'] ?? null,
            ],
        ];

        foreach ($consentUpdates as $consentUpdate) {
            $identifier = $consentUpdate['identifier'];
            $channel = $consentUpdate['channel'];
            $state = $consentUpdate['state'];

            if (! $identifier || ! $state) {
                continue;
            }

            Log::info('SHOPIFY MARKETING CONSENT WEBHOOK', [
                'customer_id' => $customerId,
                'identifier' => $identifier,
                'channel' => $channel,
                'state' => $state,
            ]);

            if (SyncEvent::wasJustWrittenByUs($identifier, $channel, $state)) {
                Log::info('Skipping Shopify → Klaviyo sync because it was already written by us', [
                    'identifier' => $identifier,
                    'channel' => $channel,
                    'state' => $state,
                ]);

                continue;
            }

            if ($state === 'subscribed') {
                $success = $klaviyo->subscribe($identifier, $channel);
            } elseif ($state === 'unsubscribed') {
                $success = $klaviyo->unsubscribe($identifier, $channel);
            } else {
                Log::warning('Unknown Shopify marketing consent state', [
                    'identifier' => $identifier,
                    'channel' => $channel,
                    'state' => $state,
                ]);

                continue;
            }

            Log::info('Shopify → Klaviyo marketing consent sync', [
                'customer_id' => $customerId,
                'identifier' => $identifier,
                'channel' => $channel,
                'state' => $state,
                'success' => $success,
            ]);
        }

        return response()->noContent();
    }
}
