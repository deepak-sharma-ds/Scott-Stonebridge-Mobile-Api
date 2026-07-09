<?php

namespace App\Contracts\Klaviyo;

use Illuminate\Support\Carbon;

interface KlaviyoApiClientInterface
{
    /**
     * List email campaign messages whose campaign was scheduled after the
     * given time. Returns one entry per SENT campaign.
     *
     * @return array<int, array{campaign_id: string, campaign_name: string|null, send_time: string|null}>
     */
    public function getRecentlySentCampaigns(Carbon $since): array;

    /**
     * Fetch one page of "Received Email" events attributed to a campaign.
     *
     * @return array{events: array<int, array{email: string|null, event_id: string|null}>, next_cursor: string|null}
     */
    public function getReceivedEmailEvents(string $campaignId, ?string $cursor = null): array;

    /**
     * Resolve a Klaviyo metric id by its name (e.g. "Received Email").
     */
    public function getMetricId(string $name): ?string;
}
