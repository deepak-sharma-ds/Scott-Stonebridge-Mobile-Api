<?php

namespace App\Http\Controllers;

use App\Models\CampaignDelivery;
use App\Models\EmailReadingDelivery;
use App\Support\OpenClientClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Open Tracking pixel. Always answers with the image - an unknown token or a
 * debounced fetch must look identical to a recorded one.
 */
class EmailTrackingController extends Controller
{
    /** A 1x1 transparent GIF (43 bytes). */
    private const PIXEL = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    /** Fetches closer together than this are one open (scrolling, multipart rendering). */
    private const DEBOUNCE_SECONDS = 5;

    private const USER_AGENT_LIMIT = 500;

    public function track(Request $request, string $token): Response
    {
        if (Str::isUuid($token) && Cache::add("email-open-debounce:{$token}", true, self::DEBOUNCE_SECONDS)) {
            $this->recordOpen($token, $request);
        }

        return response(self::PIXEL, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen(self::PIXEL),
            'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function recordOpen(string $token, Request $request): void
    {
        $delivery = EmailReadingDelivery::query()->where('tracking_token', $token)->first()
            ?? CampaignDelivery::query()->where('tracking_token', $token)->first();

        if ($delivery === null) {
            return;
        }

        $now = Carbon::now();

        $clientType = OpenClientClassifier::classify($request->userAgent(), $request->ip());

        $attributes = [
            'opened_at' => $delivery->opened_at ?? $now,
            'last_opened_at' => $now,
        ];

        // A direct open is the strongest evidence of a human reading the email:
        // a later proxy fetch is counted but must not overwrite its details.
        if ($delivery->opened_client_type !== OpenClientClassifier::DIRECT || $clientType === OpenClientClassifier::DIRECT) {
            $attributes += [
                'opened_client_type' => $clientType,
                'opened_ip' => $request->ip(),
                'opened_user_agent' => Str::limit((string) $request->userAgent(), self::USER_AGENT_LIMIT, ''),
            ];
        }

        $delivery->increment('open_count', 1, $attributes);
    }
}
