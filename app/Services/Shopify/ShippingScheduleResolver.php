<?php

namespace App\Services\Shopify;

use Illuminate\Support\Carbon;

/**
 * Shared delivery-scheduling algorithm for email-automation flows (reading
 * and campaign). Each caller supplies its own config namespace (e.g.
 * `email_reading`, `campaign_email`) so timing windows are tuned per feature
 * even though the algorithm itself is shared.
 */
class ShippingScheduleResolver
{
    /**
     * Resolve the send time for an order's automation emails.
     *
     * Returns null when scheduling is disabled (callers then dispatch the
     * send immediately). Otherwise a single random timestamp is returned per
     * order: pass in any `scheduled_at` already stored for the same order via
     * `$existing` so a replayed/duplicate webhook cannot re-randomize the
     * time and every line item in the order sends together.
     *
     * When `$expedited` is true, the timestamp falls in the
     * `{$configNamespace}.expedite.*` hours window instead of the standard
     * `{$configNamespace}.schedule.*` days window.
     */
    public function resolveScheduledAt(?Carbon $existing, bool $expedited, string $configNamespace): ?Carbon
    {
        if ($existing) {
            return $existing;
        }

        if ($expedited) {
            return $this->resolveExpeditedAt(null, $configNamespace);
        }

        if (! (bool) config("{$configNamespace}.schedule.enabled", true)) {
            return null;
        }

        $min = max(0, (int) config("{$configNamespace}.schedule.min_days", 3));
        $max = max($min, (int) config("{$configNamespace}.schedule.max_days", 7));

        return Carbon::now()->addSeconds(random_int($min * 86400, $max * 86400));
    }

    /**
     * Resolve the pulled-forward (same-day) send time for an order.
     *
     * Pass in an already-stored expedited schedule via `$existing` so a
     * replayed/duplicate webhook cannot re-randomize the time.
     */
    public function resolveExpeditedAt(?Carbon $existing, string $configNamespace): Carbon
    {
        if ($existing) {
            return $existing;
        }

        $min = max(0, (int) config("{$configNamespace}.expedite.min_hours", 1));
        $max = max($min, (int) config("{$configNamespace}.expedite.max_hours", 24));

        return Carbon::now()->addSeconds(random_int($min * 3600, $max * 3600));
    }

    /**
     * True when the order carries a non-removed shipping line whose title or
     * code matches one of the configured same-day upgrade labels.
     */
    public function hasSameDayUpgrade(array $order, string $configNamespace): bool
    {
        $titles = (array) config("{$configNamespace}.expedite.shipping_titles", []);
        if (empty($titles)) {
            return false;
        }

        foreach ((array) ($order['shipping_lines'] ?? []) as $line) {
            if (($line['is_removed'] ?? false) === true) {
                continue;
            }

            $candidates = [
                strtolower(trim((string) ($line['title'] ?? ''))),
                strtolower(trim((string) ($line['code'] ?? ''))),
            ];

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && in_array($candidate, $titles, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
