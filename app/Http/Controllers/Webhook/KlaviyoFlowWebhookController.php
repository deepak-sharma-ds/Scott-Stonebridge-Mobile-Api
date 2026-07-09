<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\Push\SendPushToRecipientJob;
use App\Models\KlaviyoWebhookEvent;
use App\Models\PushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives Klaviyo flow "Webhook" actions placed next to an email step. The
 * body is a marketer-defined JSON template carrying the recipient email plus
 * hardcoded flow/message identifiers and push copy. Responds 200 quickly so
 * Klaviyo does not retry; the actual send is queued.
 */
class KlaviyoFlowWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! config('push.enabled', false)) {
            return response()->json(['message' => 'Push disabled'], 200);
        }

        $payload = $request->json()->all() ?: [];
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $flowId = (string) ($payload['flow_id'] ?? '');
        $messageId = (string) ($payload['message_id'] ?? '');

        if ($email === '') {
            Log::channel('push')->warning('Klaviyo flow webhook missing email', [
                'flow_id' => $flowId,
            ]);

            return response()->json(['message' => 'No email'], 200);
        }

        $allowlist = (array) config('push.test_emails', []);
        if (! empty($allowlist) && ! in_array($email, $allowlist, true)) {
            Log::channel('push')->info('Klaviyo flow webhook skipped: email not in allowlist', [
                'flow_id' => $flowId,
            ]);

            return response()->json(['message' => 'Skipped: not in test allowlist'], 200);
        }

        // Klaviyo flow webhooks have no event id; dedupe on an hour-bucketed
        // key so retry storms collapse while a genuinely re-triggered flow
        // (days later) can still notify the same profile.
        $eventKey = KlaviyoWebhookEvent::buildEventKey($flowId, $messageId, $email);

        if (KlaviyoWebhookEvent::where('event_key', $eventKey)->exists()) {
            return response()->json(['message' => 'Already processed'], 200);
        }

        try {
            KlaviyoWebhookEvent::create([
                'event_key' => $eventKey,
                'flow_id' => $flowId,
                'recipient_email' => $email,
                'payload' => json_encode($payload),
                'received_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            // Unique-constraint race: another concurrent retry already stored it.
            Log::channel('push')->info('Klaviyo flow webhook duplicate race ignored', [
                'flow_id' => $flowId,
            ]);

            return response()->json(['message' => 'Already processed'], 200);
        }

        SendPushToRecipientJob::dispatch(
            $email,
            PushNotification::SOURCE_FLOW,
            $flowId,
            $messageId,
            (string) ($payload['title'] ?? config('push.defaults.title')),
            (string) ($payload['body'] ?? config('push.defaults.body')),
            (string) ($payload['deep_link'] ?? config('push.defaults.deep_link')),
        )
            ->onConnection(config('push.queue.connection'))
            ->onQueue(config('push.queue.default'));

        return response()->json(['message' => 'OK'], 200);
    }
}
