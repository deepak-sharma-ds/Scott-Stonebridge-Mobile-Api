<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\Booking\ProcessGoogleCalendarSyncJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class GoogleCalendarWebhookController extends Controller
{
    /**
     * Handle incoming Google Calendar push notifications (events.watch).
     */
    public function handle(Request $request): Response
    {
        $log = Log::channel('appointment_slots');

        $resourceState = (string) $request->header('X-Goog-Resource-State', '');
        $channelId = (string) $request->header('X-Goog-Channel-ID', '');
        $resourceId = (string) $request->header('X-Goog-Resource-ID', '');
        $channelToken = (string) $request->header('X-Goog-Channel-Token', '');
        $messageNumber = (string) $request->header('X-Goog-Message-Number', '');

        $log->info('Google Calendar push notification received', [
            'state' => $resourceState,
            'channel_id' => $channelId,
            'resource_id' => $resourceId,
            'message_number' => $messageNumber,
        ]);

        // Optional channel token validation
        $configuredToken = config('booking.google_channel_token');
        if (! empty($configuredToken) && ! hash_equals($configuredToken, $channelToken)) {
            $log->warning('Google Calendar webhook rejected: invalid channel token', [
                'channel_id' => $channelId,
            ]);

            return response('Forbidden', 403);
        }

        // Handshake verification ping
        if ($resourceState === 'sync') {
            $log->info('Google Calendar watch channel handshake verified (sync)', [
                'channel_id' => $channelId,
                'resource_id' => $resourceId,
            ]);

            return response('OK', 200);
        }

        // Asynchronously process the calendar changes
        ProcessGoogleCalendarSyncJob::dispatch($channelId, $resourceId);

        return response('OK', 200);
    }
}
