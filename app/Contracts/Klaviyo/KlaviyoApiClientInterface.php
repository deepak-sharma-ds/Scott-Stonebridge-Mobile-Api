<?php

namespace App\Contracts\Klaviyo;

use Illuminate\Support\Carbon;

interface KlaviyoApiClientInterface
{
    /**
     * List email campaign messages whose campaign was scheduled after the
     * given time. Returns one entry per SENT campaign, including its message
     * subject/preview_text (fetched via ?include=campaign-messages in the
     * same request — no extra API call) so pushes can carry real content
     * instead of generic defaults. If preview_text is blank or just repeats
     * the subject (marketer never customized it), it's replaced with a short
     * excerpt pulled from the template's own rendered text. `content` is the
     * template's full cleaned plain-text rendering (for the in-app
     * notification detail screen, not the push banner itself); null if the
     * message has no template or the template fetch fails.
     *
     * @return array<int, array{campaign_id: string, campaign_name: string|null, send_time: string|null, subject: string|null, preview_text: string|null, content: string|null}>
     */
    public function getRecentlySentCampaigns(Carbon $since): array;

    /**
     * Fetch one page of "Received Email" events attributed to a campaign.
     *
     * @param  Carbon|null  $since  Only consider events at/after this time (normally the
     *                              campaign's send_time) so the whole account's event
     *                              history isn't paged through for every sweep.
     * @return array{events: array<int, array{email: string|null, event_id: string|null}>, next_cursor: string|null}
     */
    public function getReceivedEmailEvents(string $campaignId, ?string $cursor = null, ?Carbon $since = null): array;

    /**
     * Resolve a Klaviyo metric id by its name (e.g. "Received Email").
     */
    public function getMetricId(string $name): ?string;

    /**
     * Fetch a flow message's rendered subject/preview text, used to
     * auto-populate push copy for flow-triggered pushes so it matches
     * whatever the marketer set up in Klaviyo. Result is cached (the same
     * message fires for many profiles); returns null if the message can't be
     * found or the API call fails — callers should fall back gracefully. Same
     * template-excerpt fallback as {@see getRecentlySentCampaigns()} applies
     * when preview_text is blank or repeats the subject.
     *
     * @return array{subject: string|null, preview_text: string|null}|null
     */
    public function getFlowMessageContent(string $messageId): ?array;
}
