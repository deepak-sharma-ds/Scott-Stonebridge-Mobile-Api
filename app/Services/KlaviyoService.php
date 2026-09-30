<?php

namespace App\Services;

use App\Models\SyncEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class KlaviyoService
{
    /**
     * Klaviyo's hard per-request caps on the bulk subscription jobs.
     * subscribeBatch()/unsubscribeBatch() chunk to these internally.
     */
    private const MAX_SUBSCRIBE_BATCH = 1000;

    private const MAX_UNSUBSCRIBE_BATCH = 1000;

    protected string $baseUrl = 'https://a.klaviyo.com/api';

    protected string $apiKey;

    protected string $revision;

    public function __construct()
    {
        $this->apiKey = config('services.klaviyo.api_key');
        $this->revision = config('services.klaviyo.revision');
    }

    protected function client()
    {
        return Http::withHeaders([
            'Authorization' => "Klaviyo-API-Key {$this->apiKey}",
            'revision' => $this->revision,
            'Content-Type' => 'application/json',
        ])
            ->baseUrl($this->baseUrl)
            ->timeout(15)
            ->connectTimeout(5)
            ->retry(3, 500, function (Throwable $exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            }, throw: false);
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
        return $this->unsubscribeBatch([$identifier], $channel);
    }

    /**
     * (Re)subscribe a profile - useful if you also want to sync opt-ins,
     * not just unsubscribes.
     */
    public function subscribe(string $identifier, string $channel = 'email'): bool
    {
        return $this->subscribeBatch([$identifier], $channel);
    }

    /**
     * Subscribe up to MAX_SUBSCRIBE_BATCH profiles per Klaviyo API call,
     * chunking transparently if given more. Use this instead of calling
     * subscribe() in a loop when syncing many profiles.
     *
     * @param  array<int, string>  $identifiers  Emails or phone numbers
     */
    public function subscribeBatch(array $identifiers, string $channel = 'email'): bool
    {
        return $this->chunkedBulkRequest($identifiers, $channel, 'subscribe', self::MAX_SUBSCRIBE_BATCH);
    }

    /**
     * Unsubscribe up to MAX_UNSUBSCRIBE_BATCH profiles per Klaviyo API call,
     * chunking transparently if given more. Use this instead of calling
     * unsubscribe() in a loop when syncing many profiles.
     *
     * @param  array<int, string>  $identifiers  Emails or phone numbers
     */
    public function unsubscribeBatch(array $identifiers, string $channel = 'email'): bool
    {
        return $this->chunkedBulkRequest($identifiers, $channel, 'unsubscribe', self::MAX_UNSUBSCRIBE_BATCH);
    }

    /**
     * @param  array<int, string>  $identifiers
     */
    private function chunkedBulkRequest(array $identifiers, string $channel, string $action, int $chunkSize): bool
    {
        if ($identifiers === []) {
            return true;
        }

        if (! in_array($channel, ['email', 'sms'], true)) {
            Log::channel('klaviyo_marketing_consent')->warning('Unsupported Klaviyo subscription channel', ['channel' => $channel]);

            return false;
        }

        $allSucceeded = true;

        foreach (array_chunk($identifiers, $chunkSize) as $chunk) {
            if (! $this->bulkSubscriptionRequest($chunk, $channel, $action)) {
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
    }

    /**
     * Send a single bulk subscribe/unsubscribe request. Caller must keep
     * $identifiers within Klaviyo's per-request cap (chunkedBulkRequest
     * handles that).
     *
     * @param  array<int, string>  $identifiers
     */
    private function bulkSubscriptionRequest(array $identifiers, string $channel, string $action): bool
    {
        $identifierAttribute = $channel === 'sms' ? 'phone_number' : 'email';
        $consent = $action === 'subscribe' ? 'SUBSCRIBED' : 'UNSUBSCRIBED';

        $profiles = [];
        $usedIdentifiers = [];

        foreach ($identifiers as $identifier) {
            if ($channel === 'sms') {
                $identifier = $this->normalizePhoneNumber($identifier);

                if ($identifier === null) {
                    continue;
                }
            }

            $profiles[] = [
                'type' => 'profile',
                'attributes' => [
                    $identifierAttribute => $identifier,
                    'subscriptions' => [
                        $channel => [
                            'marketing' => [
                                'consent' => $consent,
                            ],
                        ],
                    ],
                ],
            ];

            $usedIdentifiers[] = $identifier;
        }

        if ($profiles === []) {
            return true;
        }

        $endpoint = $action === 'subscribe'
            ? '/profile-subscription-bulk-create-jobs/'
            : '/profile-subscription-bulk-delete-jobs/';

        $jobType = $action === 'subscribe'
            ? 'profile-subscription-bulk-create-job'
            : 'profile-subscription-bulk-delete-job';

        $response = $this->client()->post($endpoint, [
            'data' => [
                'type' => $jobType,
                'attributes' => [
                    'profiles' => ['data' => $profiles],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::channel('klaviyo_marketing_consent')->error("Klaviyo {$action} request failed", [
                'channel' => $channel,
                'count' => count($profiles),
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return false;
        }

        $state = $action === 'subscribe' ? 'subscribed' : 'unsubscribed';
        $now = now();

        $rows = array_map(fn (string $identifier) => [
            'email' => strtolower($identifier),
            'channel' => $channel,
            'state' => $state,
            'source' => 'klaviyo',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $usedIdentifiers);

        foreach (array_chunk($rows, 500) as $chunk) {
            SyncEvent::insert($chunk);
        }

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
