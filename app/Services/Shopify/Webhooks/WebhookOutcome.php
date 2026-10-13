<?php

declare(strict_types=1);

namespace App\Services\Shopify\Webhooks;

use App\Models\ShopifyWebhookEvent;

/**
 * What a webhook handler did with an event. A handler returns an outcome for
 * every non-error path so the inbox records WHY nothing happened; a real
 * failure is an exception (the job retries it, then marks it failed).
 */
final readonly class WebhookOutcome
{
    private function __construct(
        public string $status,
        public ?string $reason,
    ) {}

    public static function processed(?string $reason = null): self
    {
        return new self(ShopifyWebhookEvent::STATUS_PROCESSED, $reason);
    }

    public static function ignored(string $reason): self
    {
        return new self(ShopifyWebhookEvent::STATUS_IGNORED, $reason);
    }
}
