<?php

namespace App\Contracts\Shopify;

use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\Webhooks\WebhookOutcome;

interface ShopifyWebhookHandlerInterface
{
    /**
     * Process one stored webhook event. Must be idempotent: the event may be
     * replayed. Throw on a real failure; return an outcome otherwise.
     */
    public function handle(ShopifyWebhookEvent $event): WebhookOutcome;
}
