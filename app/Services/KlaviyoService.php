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
    public function unsubscribe(string $email, string $channel = 'email'): bool
    {
        $subscriptions = $channel === 'sms'
            ? ['sms' => ['marketing' => ['consent' => 'UNSUBSCRIBED']]]
            : ['email' => ['marketing' => ['consent' => 'UNSUBSCRIBED']]];

        $response = $this->client()->post('/profile-subscription-bulk-delete-jobs/', [
            'data' => [
                'type' => 'profile-subscription-bulk-delete-job',
                'attributes' => [
                    'profiles' => [
                        'data' => [[
                            'type' => 'profile',
                            'attributes' => [
                                'email' => $email,
                                'subscriptions' => $subscriptions,
                            ],
                        ]],
                    ],
                ],
                'relationships' => [
                    'list' => [
                        'data' => ['type' => 'list', 'id' => $this->listId],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Klaviyo unsubscribe failed', [
                'email' => $email, 'status' => $response->status(), 'body' => $response->body(),
            ]);
            return false;
        }

        SyncEvent::record($email, $channel, 'unsubscribed', 'klaviyo');
        return true;
    }

    /**
     * (Re)subscribe a profile - useful if you also want to sync opt-ins,
     * not just unsubscribes.
     */
    public function subscribe(string $email, string $channel = 'email'): bool
    {
        $subscriptions = $channel === 'sms'
            ? ['sms' => ['marketing' => ['consent' => 'SUBSCRIBED']]]
            : ['email' => ['marketing' => ['consent' => 'SUBSCRIBED']]];

        $response = $this->client()->post('/profile-subscription-bulk-create-jobs/', [
            'data' => [
                'type' => 'profile-subscription-bulk-create-job',
                'attributes' => [
                    'profiles' => [
                        'data' => [[
                            'type' => 'profile',
                            'attributes' => [
                                'email' => $email,
                                'subscriptions' => $subscriptions,
                            ],
                        ]],
                    ],
                ],
                'relationships' => [
                    'list' => [
                        'data' => ['type' => 'list', 'id' => $this->listId],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Klaviyo subscribe failed', [
                'email' => $email, 'status' => $response->status(), 'body' => $response->body(),
            ]);
            return false;
        }

        SyncEvent::record($email, $channel, 'subscribed', 'klaviyo');
        return true;
    }
}
