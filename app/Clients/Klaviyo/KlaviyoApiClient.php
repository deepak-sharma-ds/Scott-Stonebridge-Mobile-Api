<?php

namespace App\Clients\Klaviyo;

use App\Contracts\Klaviyo\KlaviyoApiClientInterface;
use App\Exceptions\KlaviyoApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin JSON:API client for the Klaviyo REST API (https://a.klaviyo.com/api/).
 *
 * Used by the marketing-push pipeline to discover newly SENT campaigns and
 * page through their per-recipient "Received Email" events. Retries honour
 * Klaviyo's 429 Retry-After header; 5xx responses back off exponentially.
 */
class KlaviyoApiClient implements KlaviyoApiClientInterface
{
    protected const BASE_URL = 'https://a.klaviyo.com/api';

    protected const MAX_ATTEMPTS = 3;

    public function getRecentlySentCampaigns(Carbon $since): array
    {
        // Klaviyo's filter operators are kebab-case ("greater-than", not
        // "greaterThan") — the old operator name made Klaviyo reject every
        // request with a 400, which is why this filter was disabled.
        $response = $this->get('campaigns', [
            'filter' => sprintf(
                "and(equals(messages.channel,'email'),greater-than(scheduled_at,%s))",
                $since->toIso8601String()
            ),
            // Pulls each campaign's message content (subject/preview_text) in
            // the same request via JSON:API `included`, so real campaign
            // copy is available without a second call per campaign. Sparse
            // fieldset must name the actual top-level attribute
            // (`definition`, which nests `content.subject`/`.preview_text`)
            // — not the nested keys themselves, which Klaviyo rejects with a
            // 400 "not a field on the campaign-message resource" (verified
            // live).
            'include' => 'campaign-messages',
            'fields[campaign-message]' => 'definition',
        ]);

        // campaign-message content arrives under `included`, keyed by its own
        // id (verified against a live account: attributes.definition.content).
        $messageContent = [];
        foreach ((array) ($response['included'] ?? []) as $included) {
            if (($included['type'] ?? '') !== 'campaign-message') {
                continue;
            }

            $content = (array) ($included['attributes']['definition']['content'] ?? []);
            $messageContent[(string) $included['id']] = [
                'subject' => $content['subject'] ?? null,
                'preview_text' => $content['preview_text'] ?? null,
            ];
        }

        $campaigns = [];

        foreach ((array) ($response['data'] ?? []) as $campaign) {
            $status = strtolower((string) ($campaign['attributes']['status'] ?? ''));
            if ($status !== 'sent') {
                continue;
            }

            $messageId = (string) ($campaign['relationships']['campaign-messages']['data'][0]['id'] ?? '');
            $content = $messageContent[$messageId] ?? ['subject' => null, 'preview_text' => null];

            $campaigns[] = [
                'campaign_id' => (string) ($campaign['id'] ?? ''),
                'campaign_name' => $campaign['attributes']['name'] ?? null,
                'send_time' => $campaign['attributes']['send_time']
                    ?? $campaign['attributes']['scheduled_at']
                    ?? null,
                'subject' => $content['subject'],
                'preview_text' => $content['preview_text'],
            ];
        }

        return array_values(array_filter($campaigns, fn ($c) => $c['campaign_id'] !== ''));
    }

    public function getReceivedEmailEvents(string $campaignId, ?string $cursor = null, ?Carbon $since = null): array
    {
        $metricId = (string) config('push.klaviyo.received_email_metric_id');
        if ($metricId === '') {
            throw new KlaviyoApiException('KLAVIYO_RECEIVED_EMAIL_METRIC_ID is not configured');
        }

        $filter = sprintf('equals(metric_id,"%s")', $metricId);
        if ($since !== null) {
            // Bounds the page walk to events at/after the campaign's send
            // time — without this the endpoint has no other way to narrow
            // results and pages through the account's entire event history.
            $filter = sprintf('and(%s,greater-or-equal(datetime,%s))', $filter, $since->toIso8601String());
        }

        $query = [
            'filter' => $filter,
            'include' => 'profile',
            'fields[profile]' => 'email',
            'page[size]' => (int) config('push.sweep.page_size', 200),
            'sort' => 'datetime',
        ];

        if ($cursor !== null && $cursor !== '') {
            $query['page[cursor]'] = $cursor;
        }

        $response = $this->get('events', $query);

        // Profile emails arrive in the JSON:API `included` block; events
        // reference them via relationships.profile.data.id.
        $profileEmails = [];
        foreach ((array) ($response['included'] ?? []) as $included) {
            if (($included['type'] ?? '') === 'profile') {
                $profileEmails[(string) $included['id']] = $included['attributes']['email'] ?? null;
            }
        }

        $events = [];

        foreach ((array) ($response['data'] ?? []) as $event) {
            // The metric filter cannot express campaign attribution, so it's
            // filtered client-side. Klaviyo stamps the sending campaign's id
            // on Received Email events as the `$message` property (verified
            // against a live account: it matches the id returned by the
            // Campaigns API, not `$campaign`, which is a different resource).
            $properties = (array) ($event['attributes']['event_properties'] ?? []);
            $attributedCampaign = (string) ($properties['$message'] ?? '');

            if ($attributedCampaign !== '' && $attributedCampaign !== $campaignId) {
                continue;
            }

            $profileId = (string) ($event['relationships']['profile']['data']['id'] ?? '');

            $events[] = [
                'email' => $profileEmails[$profileId] ?? null,
                'event_id' => $event['id'] ?? null,
            ];
        }

        $nextLink = $response['links']['next'] ?? null;

        return [
            'events' => $events,
            'next_cursor' => $this->extractCursor($nextLink),
        ];
    }

    public function getFlowMessageContent(string $messageId): ?array
    {
        if ($messageId === '') {
            return null;
        }

        try {
            return Cache::remember(
                "klaviyo:flow-message-content:{$messageId}",
                (int) config('push.klaviyo.message_content_cache_ttl', 900),
                function () use ($messageId) {
                    $response = $this->get("flow-messages/{$messageId}", [
                        'fields[flow-message]' => 'content',
                    ]);

                    $content = (array) ($response['data']['attributes']['content'] ?? []);

                    return [
                        'subject' => $content['subject'] ?? null,
                        'preview_text' => $content['preview_text'] ?? null,
                    ];
                }
            );
        } catch (KlaviyoApiException $e) {
            // Best-effort enrichment: an unknown/typo'd message id or a
            // Klaviyo outage shouldn't block the push — caller falls back.
            Log::channel('push')->warning('Failed to fetch Klaviyo flow message content', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getMetricId(string $name): ?string
    {
        $cursor = null;

        do {
            $query = $cursor ? ['page[cursor]' => $cursor] : [];
            $response = $this->get('metrics', $query);

            foreach ((array) ($response['data'] ?? []) as $metric) {
                if (($metric['attributes']['name'] ?? null) === $name) {
                    return (string) $metric['id'];
                }
            }

            $cursor = $this->extractCursor($response['links']['next'] ?? null);
        } while ($cursor !== null);

        return null;
    }

    /**
     * Execute a GET request with retry. 429 waits for Retry-After; 5xx and
     * connection failures back off exponentially; other 4xx fail immediately.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        $attempt = 0;
        $delaySeconds = 1;

        while (true) {
            $attempt++;

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Klaviyo-API-Key '.(string) config('push.klaviyo.api_key'),
                    'revision' => (string) config('push.klaviyo.revision'),
                    'accept' => 'application/vnd.api+json',
                ])->timeout(30)->get(self::BASE_URL.'/'.ltrim($path, '/'), $query);

                if ($response->successful()) {
                    return (array) $response->json();
                }

                if (! $this->isRetryableStatus($response->status()) || $attempt >= self::MAX_ATTEMPTS) {
                    throw new KlaviyoApiException(
                        "Klaviyo API {$path} failed with status {$response->status()}: ".$response->body(),
                        $response->status()
                    );
                }

                $wait = $this->resolveRetryDelay($response, $delaySeconds);
                $this->logRetry($path, $attempt, $response->status(), $wait);
                sleep($wait);
                $delaySeconds *= 2;
            } catch (KlaviyoApiException $e) {
                throw $e;
            } catch (\Exception $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new KlaviyoApiException(
                        "Klaviyo API {$path} failed after {$attempt} attempts: ".$e->getMessage(),
                        0,
                        $e
                    );
                }

                $this->logRetry($path, $attempt, 0, $delaySeconds);
                sleep($delaySeconds);
                $delaySeconds *= 2;
            }
        }
    }

    protected function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    protected function resolveRetryDelay(Response $response, int $fallbackSeconds): int
    {
        $retryAfter = (int) $response->header('Retry-After');

        return $retryAfter > 0 ? min($retryAfter, 120) : $fallbackSeconds;
    }

    protected function logRetry(string $path, int $attempt, int $status, int $waitSeconds): void
    {
        Log::channel('push')->warning('Retrying Klaviyo API request', [
            'path' => $path,
            'attempt' => $attempt,
            'status' => $status,
            'wait_seconds' => $waitSeconds,
        ]);
    }

    protected function extractCursor(?string $nextLink): ?string
    {
        if (! $nextLink) {
            return null;
        }

        $query = (string) parse_url($nextLink, PHP_URL_QUERY);
        parse_str($query, $params);

        $cursor = $params['page']['cursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
