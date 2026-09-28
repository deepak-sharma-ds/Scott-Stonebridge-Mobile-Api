<?php

namespace App\Services;

use App\Models\SyncEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KlaviyoService
{
    protected string $baseUrl = 'https://a.klaviyo.com/api';
    protected string $apiKey;
    protected string $revision = '2024-10-15'; // Klaviyo API version - bump as you upgrade
    protected string $listId;

    public function __construct()
    {
        $this->apiKey = config('services.klaviyo.api_key');
        $this->listId = config('services.klaviyo.list_id');
    }

    protected function client()
    {
        return Http::withHeaders([
            'Authorization' => "Klaviyo-API-Key {$this->apiKey}",
            'revision' => $this->revision,
            'Content-Type' => 'application/json',
        ])->baseUrl($this->baseUrl);
    }

    /**
     * Unsubscribe a profile from marketing (email and/or sms) via the
     * Bulk Unsubscribe Profiles job.
     *
     * NOTE: Klaviyo's docs warn that if the profile is not a member of the
     * list you pass, they may be unsubscribed GLOBALLY instead of just from
     * that list. If you only want a scoped unsubscribe, check list
     * membership first (GET /api/lists/{id}/relationships/profiles).
     */
    public function unsubscribe(string $identifier, string $channel = 'email'): bool
    {
        if (! in_array($channel, ['email', 'sms'], true)) {
            Log::warning('Unsupported Klaviyo subscription channel', ['channel' => $channel]);

            return false;
        }

        if ($channel === 'sms') {
            $identifier = $this->normalizePhoneNumber($identifier);

            if ($identifier === null) {
                Log::warning('Invalid phone number for Klaviyo SMS unsubscribe');

                return false;
            }
        }

        $identifierAttribute = $channel === 'sms' ? 'phone_number' : 'email';

        $response = $this->client()->post('/profile-subscription-bulk-delete-jobs/', [
            'data' => [
                'type' => 'profile-subscription-bulk-delete-job',
                'attributes' => [
                    'profiles' => [
                        'data' => [
                            [
                                'type' => 'profile',
                                'attributes' => [
                                    $identifierAttribute => $identifier,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Klaviyo unsubscribe request failed', [
                'channel' => $channel,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return false;
        }

        SyncEvent::record($identifier, $channel, 'unsubscribed', 'klaviyo');

        return true;
    }

    /**
     * (Re)subscribe a profile - useful if you also want to sync opt-ins,
     * not just unsubscribes.
     */
    public function subscribe(string $identifier, string $channel = 'email'): bool
    {
        if (! in_array($channel, ['email', 'sms'], true)) {
            Log::warning('Unsupported Klaviyo subscription channel', ['channel' => $channel]);

            return false;
        }

        if ($channel === 'sms') {
            $identifier = $this->normalizePhoneNumber($identifier);

            if ($identifier === null) {
                Log::warning('Invalid phone number for Klaviyo SMS subscribe');

                return false;
            }
        }

        $identifierAttribute = $channel === 'sms' ? 'phone_number' : 'email';

        $response = $this->client()->post('/profile-subscription-bulk-create-jobs/', [
            'data' => [
                'type' => 'profile-subscription-bulk-create-job',
                'attributes' => [
                    'profiles' => [
                        'data' => [
                            [
                                'type' => 'profile',
                                'attributes' => [
                                    $identifierAttribute => $identifier,
                                    'subscriptions' => [
                                        $channel => [
                                            'marketing' => [
                                                'consent' => 'SUBSCRIBED',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Klaviyo subscribe request failed', [
                'channel' => $channel,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return false;
        }

        SyncEvent::record($identifier, $channel, 'subscribed', 'klaviyo');

        return true;
    }

    public function normalizePhoneNumber(string $phoneNumber): ?string
    {
        $normalizedPhoneNumber = preg_replace('/[\s()-]+/', '', $phoneNumber);

        if (
            ! is_string($normalizedPhoneNumber)
            || ! preg_match('/^\+[1-9]\d{1,14}$/', $normalizedPhoneNumber)
        ) {
            return null;
        }

        return $normalizedPhoneNumber;
    }
}
