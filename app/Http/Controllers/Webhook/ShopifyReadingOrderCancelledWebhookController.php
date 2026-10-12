<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Shopify orders/cancelled and refunds/create for Email Reading
 * Automation. Stores the event and queues its processing; the work happens in
 * ReadingOrderCancelledHandler.
 */
class ShopifyReadingOrderCancelledWebhookController extends Controller
{
    public function __construct(private readonly WebhookInbox $inbox) {}

    public function handle(Request $request): JsonResponse
    {
        return $this->inbox->receive($request, 'reading.cancelled', 'orders/cancelled');
    }
}
